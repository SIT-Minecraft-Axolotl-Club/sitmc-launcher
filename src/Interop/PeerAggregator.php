<?php

namespace SITMinecraft\Launcher\Interop;

/**
 * Polls the peer allowlist and hands the last good snapshots to the serializer.
 *
 * Cross-site aggregation happens on the site, never in the launcher (§7):
 * conditional pull, per-peer snapshot, failure keeps the last snapshot with
 * `source.stale: true`, and a malformed peer document never clears content.
 */
final class PeerAggregator
{
    public function __construct(
        private InteropRepository $repository,
        private PeerFetcher $fetcher,
    ) {}

    /**
     * @return array{documents: array<int, array<string, mixed>>, diagnostics: array<int, array<string, mixed>>}
     */
    public function peerDocuments(): array
    {
        $settings = $this->repository->settings();

        if (! $settings['aggregate']) {
            return ['documents' => [], 'diagnostics' => []];
        }

        $peers = $this->repository->peers(true);

        if ($peers === []) {
            return ['documents' => [], 'diagnostics' => []];
        }

        $snapshots = $this->repository->snapshots();
        $documents = [];
        $diagnostics = [];

        foreach ($peers as $peer) {
            $siteId = $peer['site_id'];
            $snapshot = $snapshots[$siteId] ?? null;

            if ($snapshot === null || $snapshot['document'] === null) {
                $diagnostics[] = ['code' => 'peer-no-snapshot', 'siteId' => $siteId];
                continue;
            }

            $document = json_decode($snapshot['document'], true);

            if (! is_array($document)) {
                $diagnostics[] = ['code' => 'peer-snapshot-invalid', 'siteId' => $siteId];
                continue;
            }

            $origin = $snapshot['origin'] !== '' ? $snapshot['origin'] : $peer['origin'];

            $documents[] = [
                'siteId' => $siteId,
                'domain' => InteropRules::host(parse_url($origin, PHP_URL_HOST)),
                'origin' => $origin,
                'stale' => (bool) $snapshot['stale'],
                'document' => $document,
            ];

            if ($snapshot['last_error'] !== null) {
                $diagnostics[] = [
                    'code' => 'peer-sync-failed',
                    'siteId' => $siteId,
                    'detail' => $snapshot['last_error'],
                ];
            }
        }

        return ['documents' => $documents, 'diagnostics' => $diagnostics];
    }

    /**
     * @return array{ok: int, failed: int, skipped?: bool, results: array<string, array<string, mixed>>}
     */
    public function sync(?string $siteId = null): array
    {
        $lock = $this->lock();

        if ($lock === null) {
            return ['ok' => 0, 'failed' => 0, 'skipped' => true, 'results' => []];
        }

        try {
            return $this->runSync($siteId);
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    /**
     * @return array{ok: int, failed: int, results: array<string, array<string, mixed>>}
     */
    private function runSync(?string $siteId): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $peers = $siteId === null
            ? $this->repository->peers(true)
            : array_values(array_filter(
                $this->repository->peers(),
                static fn (array $peer): bool => $peer['site_id'] === $siteId
            ));

        $results = [];
        $ok = 0;
        $failed = 0;

        foreach ($peers as $peer) {
            $result = $this->syncPeer($peer);
            $results[$peer['site_id']] = $result;

            if (($result['status'] ?? null) === 'ok') {
                $ok++;
            } else {
                $failed++;
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'results' => $results];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function peerStatus(): array
    {
        $snapshots = $this->repository->snapshots();
        $status = [];

        foreach ($this->repository->peers() as $peer) {
            $snapshot = $snapshots[$peer['site_id']] ?? null;
            $document = $snapshot === null || $snapshot['document'] === null
                ? null
                : json_decode($snapshot['document'], true);
            $packs = is_array($document) && is_array($document['packs'] ?? null)
                ? count($document['packs'])
                : 0;

            $state = 'never';

            if ($snapshot !== null && $snapshot['document'] !== null) {
                $state = $snapshot['stale'] ? 'stale' : 'ok';
            }

            $status[] = [
                'site_id' => $peer['site_id'],
                'name' => $peer['name'],
                'description' => $peer['description'],
                'origin' => $peer['origin'],
                'domain' => InteropRules::host(parse_url($peer['origin'], PHP_URL_HOST)) ?? '',
                'enabled' => $peer['enabled'],
                'state' => $state,
                'fetched_at' => $snapshot['fetched_at'] ?? null,
                'last_error' => $snapshot['last_error'] ?? null,
                'packs' => $packs,
            ];
        }

        return $status;
    }

    /**
     * When a peer is due, refresh it after the response has been sent.
     */
    public function scheduleRefreshIfStale(): void
    {
        $settings = $this->repository->settings();

        if (! $settings['aggregate']) {
            return;
        }

        $peers = $this->repository->peers(true);

        if ($peers === []) {
            return;
        }

        $ttl = max(60, (int) $settings['peer_ttl']);
        $snapshots = $this->repository->snapshots();
        $now = time();
        $due = false;

        foreach ($peers as $peer) {
            $snapshot = $snapshots[$peer['site_id']] ?? null;

            if (
                $snapshot === null
                || $snapshot['stale']
                || $snapshot['fetched_at'] === null
                || $snapshot['fetched_at'] + $ttl <= $now
            ) {
                $due = true;
                break;
            }
        }

        if (! $due) {
            return;
        }

        register_shutdown_function(function (): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }

            try {
                $this->sync();
            } catch (\Throwable) {
                // A peer outage must never surface as a failed page load.
            }
        });
    }

    /**
     * @param  array<string, mixed>  $peer
     * @return array<string, mixed>
     */
    private function syncPeer(array $peer): array
    {
        $siteId = (string) $peer['site_id'];
        $origin = rtrim((string) $peer['origin'], '/');
        $manifestUrl = trim((string) ($peer['manifest_url'] ?? ''));

        if ($manifestUrl === '') {
            $manifestUrl = $origin.InteropRules::WELL_KNOWN_PATH;
        }

        $snapshot = $this->repository->snapshot($siteId);
        $etag = $snapshot['etag'] ?? null;

        $fetched = $this->fetcher->get($manifestUrl, $etag);

        if ($fetched['error'] !== null) {
            $this->repository->markFailure($siteId, (string) $fetched['error']);

            return ['status' => 'failed', 'siteId' => $siteId, 'error' => $fetched['error']];
        }

        if ($fetched['status'] === 304) {
            $this->repository->markNotModified($siteId, $fetched['etag']);

            return ['status' => 'ok', 'siteId' => $siteId, 'notModified' => true];
        }

        $document = json_decode((string) $fetched['body'], true);

        if (! is_array($document) || ! is_array($document['packs'] ?? null)) {
            $this->repository->markFailure($siteId, 'peer-shape-invalid');

            return ['status' => 'failed', 'siteId' => $siteId, 'error' => 'peer-shape-invalid'];
        }

        $encoded = InteropRules::encode($document);

        if (strlen($encoded) > InteropRules::MAX_PEER_BYTES) {
            $this->repository->markFailure($siteId, 'peer-document-too-large');

            return ['status' => 'failed', 'siteId' => $siteId, 'error' => 'peer-document-too-large'];
        }

        $this->repository->storeSnapshot($siteId, $origin, $fetched['etag'], $encoded, time());

        return [
            'status' => 'ok',
            'siteId' => $siteId,
            'notModified' => false,
            'packs' => count($document['packs']),
        ];
    }

    /**
     * @return resource|null
     */
    private function lock()
    {
        if (! function_exists('storage_path')) {
            return null;
        }

        $path = storage_path('app/launcher-interop-sync.lock');
        $directory = dirname($path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            return null;
        }

        if (! @flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);

            return null;
        }

        return $handle;
    }
}
