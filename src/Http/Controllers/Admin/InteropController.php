<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use SITMinecraft\Launcher\Interop\InteropRepository;
use SITMinecraft\Launcher\Interop\InteropRules;
use SITMinecraft\Launcher\Interop\PeerAggregator;
use SITMinecraft\Launcher\Interop\PeerDiscovery;
use SITMinecraft\Launcher\Interop\SiteDocumentService;

/**
 * Admin screens for the mc-interop exit.
 *
 * The legacy instance screens stay untouched. This section owns the site
 * identity, the explicit publication selection and the peer allowlist.
 */
final class InteropController
{
    public function __construct(
        private InteropRepository $repository,
        private PeerAggregator $aggregator,
        private PeerDiscovery $discovery,
        private SiteDocumentService $documents,
    ) {}

    public function index(Request $request): View
    {
        $result = $this->documents->document($request->root());
        $instances = $this->repository->interopRows();

        foreach ($instances as $index => $instance) {
            $current = null;

            foreach ($instance['packs'] as $pack) {
                if ((int) ($pack['revision'] ?? -1) === (int) ($instance['revision'] ?? -2)) {
                    $current = $pack;
                    break;
                }
            }

            $instances[$index]['current_pack'] = $current;
            $instances[$index]['has_packs'] = $instance['packs'] !== [];
            $instances[$index]['has_server'] = trim((string) ($instance['server_address'] ?? '')) !== '';
        }

        $peers = $this->aggregator->peerStatus();

        foreach ($peers as $index => $peer) {
            $peers[$index]['fetched_at_text'] = $peer['fetched_at'] === null
                ? null
                : date('Y-m-d H:i:s', $peer['fetched_at']);
        }

        $domain = $result['domain'];
        $base = 'https://'.$domain;
        $syncUrl = $base.'/api/launcher/interop/v1/refresh?token='.rawurlencode($this->repository->syncToken());

        return view('sitmc-launcher::admin.interop', [
            'settings' => $result['settings'],
            'instances' => $instances,
            'peers' => $peers,
            'diagnostics' => $this->labelledDiagnostics($result['diagnostics']),
            'packCount' => count($result['document']['packs']),
            'peerCount' => count($result['document']['peers']),
            'wellKnownUrl' => $base.InteropRules::WELL_KNOWN_PATH,
            'endpointUrl' => $base.'/api/launcher/interop/v1/site',
            'friendsUrl' => $base.'/api/launcher/friends',
            'syncUrl' => $syncUrl,
            'cronCommand' => "*/5 * * * * curl -fsS -X POST --max-time 300 '".$syncUrl."' >/dev/null",
            'status' => session('sitmc_launcher_status'),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'site_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            'site_description' => ['nullable', 'string', 'max:2000'],
            'site_domain' => ['nullable', 'string', 'max:253'],
            'peer_ttl' => ['nullable', 'integer', 'min:60', 'max:86400'],
        ]);

        $domain = $this->textOrNull($values['site_domain'] ?? null);

        if ($domain !== null && InteropRules::host($domain) === null) {
            throw ValidationException::withMessages([
                'site_domain' => trans('sitmc-launcher::launcher.interop.problem.site-domain-invalid'),
            ]);
        }

        $this->repository->saveSettings([
            'site_id' => $values['site_id'],
            'site_description' => $this->textOrNull($values['site_description'] ?? null),
            'site_domain' => $domain,
            'aggregate' => $request->boolean('aggregate'),
            'peer_ttl' => (int) ($values['peer_ttl'] ?? 300),
        ]);

        return redirect()
            ->route('sitmc-launcher.admin.interop.index')
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.interop.status.settings-saved'));
    }

    public function savePublications(Request $request): JsonResponse|RedirectResponse
    {
        $values = $request->validate([
            'published' => ['nullable', 'array'],
            'published.*' => ['string', 'max:128'],
        ]);

        $known = [];

        foreach ($this->repository->interopRows() as $instance) {
            $known[(string) $instance['id']] = true;
        }

        $selected = [];

        foreach ((array) ($values['published'] ?? []) as $id) {
            if (isset($known[(string) $id])) {
                $selected[] = (string) $id;
            }
        }

        $this->repository->savePublications($selected);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'count' => count($selected)]);
        }

        return redirect()
            ->route('sitmc-launcher.admin.interop.index')
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.interop.status.publications-saved', [
                'count' => count($selected),
            ]));
    }

    public function createPeer(): View
    {
        return view('sitmc-launcher::admin.interop_peer', [
            'peer' => null,
            'manifest_url' => '',
            'status' => null,
            'peerStatus' => null,
        ]);
    }

    public function editPeer(string $siteId): View
    {
        $peer = $this->repository->peer($siteId);

        if ($peer === null) {
            abort(404);
        }

        $peerStatus = null;

        foreach ($this->aggregator->peerStatus() as $candidate) {
            if ($candidate['site_id'] === $siteId) {
                $peerStatus = $candidate;
                break;
            }
        }

        if ($peerStatus !== null) {
            $peerStatus['fetched_at_text'] = $peerStatus['fetched_at'] === null
                ? null
                : date('Y-m-d H:i:s', $peerStatus['fetched_at']);
        }

        return view('sitmc-launcher::admin.interop_peer', [
            'peer' => $peer,
            'manifest_url' => $peer['manifest_url']
                ?? rtrim((string) $peer['origin'], '/').InteropRules::WELL_KNOWN_PATH,
            'status' => session('sitmc_launcher_status'),
            'peerStatus' => $peerStatus,
        ]);
    }

    public function storePeer(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'manifest_url' => ['required', 'string', 'max:2048'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $discovered = $this->discovery->discover((string) $values['manifest_url']);

        if (! isset($discovered['manifest_url'], $discovered['domain'], $discovered['origin'])) {
            throw ValidationException::withMessages([
                'manifest_url' => trans('sitmc-launcher::launcher.interop.problem.peer-manifest-url-invalid'),
            ]);
        }

        $domain = (string) $discovered['domain'];
        $enabled = (bool) ($values['enabled'] ?? true);
        $settings = $this->repository->settings();

        if (
            $domain === $this->documents->domain($request->root())
            || ($settings['site_domain'] !== null && $domain === $settings['site_domain'])
        ) {
            throw ValidationException::withMessages([
                'manifest_url' => trans('sitmc-launcher::launcher.interop.problem.peer-self'),
            ]);
        }

        if ($this->repository->peerByManifest((string) $discovered['manifest_url']) !== null) {
            throw ValidationException::withMessages([
                'manifest_url' => trans('sitmc-launcher::launcher.interop.problem.peer-manifest-taken'),
            ]);
        }

        if (! ($discovered['ok'] ?? false)) {
            $this->saveOfflinePeer($discovered, $enabled);

            return redirect()
                ->route('sitmc-launcher.admin.interop.index')
                ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.interop.status.peer-created-offline'));
        }

        $siteId = (string) $discovered['site_id'];

        if ($siteId === $settings['site_id']) {
            throw ValidationException::withMessages([
                'manifest_url' => trans('sitmc-launcher::launcher.interop.problem.peer-self'),
            ]);
        }

        if ($this->repository->peer($siteId) !== null) {
            throw ValidationException::withMessages([
                'manifest_url' => trans('sitmc-launcher::launcher.interop.problem.peer-site-id-taken'),
            ]);
        }

        $this->saveDiscoveredPeer($discovered, $enabled);

        return redirect()
            ->route('sitmc-launcher.admin.interop.index')
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.interop.status.peer-created'));
    }

    public function updatePeer(Request $request, string $siteId): RedirectResponse
    {
        $peer = $this->repository->peer($siteId);

        if ($peer === null) {
            abort(404);
        }

        $values = $request->validate([
            'manifest_url' => ['required', 'string', 'max:2048'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $discovered = $this->discovery->discover((string) $values['manifest_url']);

        if (! isset($discovered['manifest_url'], $discovered['domain'], $discovered['origin'])) {
            throw ValidationException::withMessages([
                'manifest_url' => trans('sitmc-launcher::launcher.interop.problem.peer-manifest-url-invalid'),
            ]);
        }

        $enabled = (bool) ($values['enabled'] ?? false);
        $currentManifest = $peer['manifest_url']
            ?? rtrim((string) $peer['origin'], '/').InteropRules::WELL_KNOWN_PATH;
        $currentManifest = InteropRules::publicHttpsUrl((string) $currentManifest);

        // Toggling "enabled" must not depend on the peer being online.
        if ($currentManifest !== null && $discovered['manifest_url'] === $currentManifest) {
            $this->repository->savePeer([
                'site_id' => $siteId,
                'name' => $peer['name'],
                'description' => $peer['description'],
                'origin' => $peer['origin'],
                'manifest_url' => $peer['manifest_url'],
                'enabled' => $enabled,
            ]);

            return redirect()
                ->route('sitmc-launcher.admin.interop.index')
                ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.interop.status.peer-saved'));
        }

        $settings = $this->repository->settings();

        if (
            $discovered['domain'] === $this->documents->domain($request->root())
            || ($settings['site_domain'] !== null && $discovered['domain'] === $settings['site_domain'])
        ) {
            throw ValidationException::withMessages([
                'manifest_url' => trans('sitmc-launcher::launcher.interop.problem.peer-self'),
            ]);
        }

        $other = $this->repository->peerByManifest((string) $discovered['manifest_url']);

        if ($other !== null && $other['site_id'] !== $siteId) {
            throw ValidationException::withMessages([
                'manifest_url' => trans('sitmc-launcher::launcher.interop.problem.peer-manifest-taken'),
            ]);
        }

        if (! ($discovered['ok'] ?? false)) {
            $this->saveOfflinePeer($discovered, $enabled, $siteId);

            return redirect()
                ->route('sitmc-launcher.admin.interop.index')
                ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.interop.status.peer-saved-offline'));
        }

        $newSiteId = (string) $discovered['site_id'];

        if ($newSiteId === $settings['site_id']) {
            throw ValidationException::withMessages([
                'manifest_url' => trans('sitmc-launcher::launcher.interop.problem.peer-self'),
            ]);
        }

        if ($newSiteId !== $siteId) {
            if (! $this->repository->adoptPeerIdentity($siteId, $newSiteId, $discovered['name'], $discovered['description'])) {
                throw ValidationException::withMessages([
                    'manifest_url' => trans('sitmc-launcher::launcher.interop.problem.peer-site-id-taken'),
                ]);
            }
        }

        $this->saveDiscoveredPeer($discovered, $enabled);

        return redirect()
            ->route('sitmc-launcher.admin.interop.index')
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.interop.status.peer-saved'));
    }

    public function destroyPeer(string $siteId): RedirectResponse
    {
        $this->repository->deletePeer($siteId);

        return redirect()
            ->route('sitmc-launcher.admin.interop.index')
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.interop.status.peer-deleted'));
    }

    public function rotateSyncToken(): RedirectResponse
    {
        $this->repository->rotateSyncToken();

        return redirect()
            ->route('sitmc-launcher.admin.interop.index')
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.interop.status.token-rotated'));
    }

    public function syncPeers(): RedirectResponse
    {
        $result = $this->aggregator->sync();

        if ((bool) ($result['skipped'] ?? false)) {
            return redirect()
                ->route('sitmc-launcher.admin.interop.index')
                ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.interop.status.peers-sync-busy'));
        }

        return redirect()
            ->route('sitmc-launcher.admin.interop.index')
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.interop.status.peers-synced', [
                'ok' => $result['ok'],
                'failed' => $result['failed'],
            ]));
    }

    public function syncPeer(string $siteId): RedirectResponse
    {
        if ($this->repository->peer($siteId) === null) {
            abort(404);
        }

        $result = $this->aggregator->sync($siteId);

        if ((bool) ($result['skipped'] ?? false)) {
            return redirect()
                ->route('sitmc-launcher.admin.interop.index')
                ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.interop.status.peers-sync-busy'));
        }

        $entry = $result['results'][$siteId] ?? null;

        if (($entry['status'] ?? null) === 'ok') {
            $message = trans('sitmc-launcher::launcher.interop.status.peer-synced', ['site' => $siteId]);
        } else {
            $message = trans('sitmc-launcher::launcher.interop.status.peer-sync-failed', [
                'site' => $siteId,
                'error' => (string) ($entry['error'] ?? 'unknown'),
            ]);
        }

        return redirect()
            ->route('sitmc-launcher.admin.interop.index')
            ->with('sitmc_launcher_status', $message);
    }

    /**
     * Save a manifest URL without a successful pull. The placeholder id is
     * replaced by the peer's real site id on the first successful sync.
     *
     * @param  array<string, mixed>  $discovered
     */
    private function saveOfflinePeer(array $discovered, bool $enabled, ?string $oldSiteId = null): string
    {
        $manifestUrl = (string) $discovered['manifest_url'];
        $domain = (string) $discovered['domain'];
        $origin = (string) $discovered['origin'];
        $base = $this->discovery->pendingId($manifestUrl);
        $pending = $base;
        $counter = 1;

        while (($existing = $this->repository->peer($pending)) !== null && $existing['site_id'] !== $oldSiteId) {
            $pending = $base.'-'.$counter++;
        }

        if ($oldSiteId !== null && $oldSiteId !== $pending) {
            if (! $this->repository->adoptPeerIdentity($oldSiteId, $pending, $domain, null)) {
                throw ValidationException::withMessages([
                    'manifest_url' => trans('sitmc-launcher::launcher.interop.problem.peer-site-id-taken'),
                ]);
            }
        }

        $this->repository->savePeer([
            'site_id' => $pending,
            'name' => $domain,
            'description' => null,
            'origin' => $origin,
            'manifest_url' => $manifestUrl,
            'enabled' => $enabled,
        ]);

        $this->repository->deleteSnapshot($pending);

        return $pending;
    }

    /**
     * @param  array<string, mixed>  $discovered
     */
    private function saveDiscoveredPeer(array $discovered, bool $enabled): void
    {
        $siteId = (string) $discovered['site_id'];

        $this->repository->savePeer([
            'site_id' => $siteId,
            'name' => $discovered['name'] ?? $siteId,
            'description' => $discovered['description'] ?? null,
            'origin' => $discovered['origin'],
            'manifest_url' => $discovered['manifest_url'],
            'enabled' => $enabled,
        ]);

        $this->repository->storeSnapshot(
            $siteId,
            (string) $discovered['origin'],
            $discovered['etag'] ?? null,
            InteropRules::encode($discovered['document']),
            time()
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function labelledDiagnostics(array $diagnostics): array
    {
        $labelled = [];

        foreach ($diagnostics as $diagnostic) {
            $code = (string) ($diagnostic['code'] ?? '');
            $key = 'sitmc-launcher::launcher.interop.diagnostic.'.$code;

            if (is_array($diagnostic['detail'] ?? null)) {
                $diagnostic['detail'] = InteropRules::encode($diagnostic['detail']);
            }

            $diagnostic['label'] = Lang::has($key) ? trans($key) : $code;
            $labelled[] = $diagnostic;
        }

        return $labelled;
    }

    private function textOrNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
