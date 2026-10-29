<?php

namespace SITMinecraft\Launcher\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SITMinecraft\Launcher\Interop\InteropRepository;
use SITMinecraft\Launcher\Interop\InteropRules;
use SITMinecraft\Launcher\Interop\PeerAggregator;
use SITMinecraft\Launcher\Interop\SiteDocumentService;

/**
 * The unified mc-interop v1 exit.
 *
 * Same bytes at the canonical `/.well-known/mc-interop-v1.json` and at the
 * plugin-visible `/api/launcher/interop/v1/site` mirror a reverse proxy can
 * forward to. The legacy `/api/launcher/*` endpoints are untouched.
 */
final class InteropController
{
    public function __construct(
        private SiteDocumentService $documents,
        private InteropRepository $repository,
        private PeerAggregator $aggregator,
    ) {}

    public function site(Request $request): Response
    {
        $this->documents->scheduleRefresh();
        $result = $this->documents->document($request->root());

        return $this->respond($request, $result['document']);
    }

    public function friends(Request $request): Response
    {
        $this->documents->scheduleRefresh();
        $result = $this->documents->friends($request->root());

        return $this->respond($request, $result['document']);
    }

    /**
     * Curated instances for the launcher home page, in the legacy manifest
     * instance shape. The administrator picks from local and peer instances;
     * featured entries always carry `sort = -1` so the launcher renders them
     * before everything else. This endpoint is launcher-only: the selection is
     * never part of the mc-interop documents.
     */
    public function featured(Request $request): Response
    {
        $this->documents->scheduleRefresh();

        $site = $this->documents->document($request->root())['document'];
        $friends = $this->documents->friends($request->root())['document'];
        $featured = $this->repository->featured();

        $local = [];
        $peers = [];

        foreach ($site['packs'] as $entry) {
            $local[(string) $entry['id']] = $entry;
        }

        foreach ($friends['sites'] ?? [] as $siteRow) {
            $peers[(string) $siteRow['site_id']] = $siteRow;
        }

        $instances = [];

        foreach ($featured as $row) {
            $peerSiteId = $row['peer_site_id'];
            $entry = $peerSiteId === null
                ? ($local[$row['instance_id']] ?? null)
                : ($peers[$peerSiteId]['instances'][$row['instance_id']] ?? null);

            if ($entry === null) {
                continue;
            }

            $artifact = is_array($entry['artifact'] ?? null) ? $entry['artifact'] : null;
            $loader = $entry['loader'] ?? null;
            $name = (string) ($entry['name'] ?? $entry['id'] ?? '');

            $instances[] = [
                'id' => $peerSiteId === null
                    ? (string) $entry['id']
                    : $peerSiteId.':'.(string) $entry['id'],
                'name' => $row['label'] !== null && $row['label'] !== '' ? $row['label'] : $name,
                'description' => $entry['summary'] ?? null,
                'icon_url' => null,
                // Featured instances sort before everything else.
                'sort' => -1,
                'revision' => $artifact === null ? null : (int) ($entry['revision'] ?? 0),
                'minecraft' => [
                    'game_version' => (string) ($entry['gameVersion'] ?? ''),
                    'loader' => $loader === 'vanilla' ? null : $loader,
                    'loader_version' => $entry['loaderVersion'] ?? null,
                ],
                'pack' => $artifact === null ? null : [
                    'kind' => (string) ($artifact['format'] ?? 'mrpack'),
                    'url' => (string) ($artifact['url'] ?? ''),
                    'sha1' => $artifact['sha1'] ?? null,
                    'size' => isset($artifact['size']) ? (int) $artifact['size'] : null,
                ],
                'server' => [
                    'address' => (string) ($entry['server']['address'] ?? ''),
                    'port' => isset($entry['server']['port']) ? (int) $entry['server']['port'] : null,
                ],
                'required' => false,
            ];
        }

        $document = [
            'schema' => 1,
            'generated_at' => InteropRules::isoUtc(time()),
            'launcher' => [
                'min_version' => null,
                'latest_version' => null,
                'force_update' => false,
            ],
            'instances' => $instances,
        ];

        return $this->respond($request, $document);
    }

    /**
     * Token-protected endpoint for a real cron job: pulls every enabled peer
     * regardless of catalog traffic. The admin page shows the exact command.
     */
    public function refresh(Request $request): Response
    {
        $provided = $request->header('X-Interop-Token');

        if (! is_string($provided) || trim($provided) === '') {
            $provided = $request->query('token');
        }

        $provided = is_string($provided) ? trim($provided) : '';
        $expected = $this->repository->syncToken();

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response(InteropRules::encode(['message' => 'Forbidden']), 403, [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
        }

        $result = $this->aggregator->sync();

        $payload = [
            'ok' => $result['ok'],
            'failed' => $result['failed'],
            'skipped' => (bool) ($result['skipped'] ?? false),
            'results' => array_map(static fn (array $entry): array => [
                'siteId' => $entry['siteId'] ?? null,
                'status' => $entry['status'] ?? null,
                'error' => $entry['error'] ?? null,
            ], $result['results']),
            'syncedAt' => InteropRules::isoUtc(time()),
        ];

        return response(InteropRules::encode($payload), 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function respond(Request $request, array $payload): Response
    {
        $body = InteropRules::encode($payload);
        $etag = InteropRules::etag($body);
        $cacheControl = 'public, max-age=300';

        if (InteropRules::ifNoneMatch($request->header('If-None-Match'), $etag)) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => $cacheControl,
            ]);
        }

        return response($body, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'ETag' => $etag,
            'Cache-Control' => $cacheControl,
        ]);
    }
}
