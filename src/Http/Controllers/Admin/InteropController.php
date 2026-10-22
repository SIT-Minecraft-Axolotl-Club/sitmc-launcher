<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use SITMinecraft\Launcher\Interop\InteropRepository;
use SITMinecraft\Launcher\Interop\InteropRules;
use SITMinecraft\Launcher\Interop\PeerAggregator;
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

    public function savePublications(Request $request): RedirectResponse
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
            'domain' => '',
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
            'domain' => InteropRules::host(parse_url($peer['origin'], PHP_URL_HOST)) ?? '',
            'status' => session('sitmc_launcher_status'),
            'peerStatus' => $peerStatus,
        ]);
    }

    public function storePeer(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'site_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['nullable', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:2000'],
            'domain' => ['required', 'string', 'max:253'],
            'manifest_url' => ['nullable', 'string', 'max:512'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $siteId = (string) $values['site_id'];

        if ($this->repository->peer($siteId) !== null) {
            throw ValidationException::withMessages([
                'site_id' => trans('sitmc-launcher::launcher.interop.problem.peer-site-id-taken'),
            ]);
        }

        $this->repository->savePeer($this->peerValues($values, $siteId));

        return redirect()
            ->route('sitmc-launcher.admin.interop.index')
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.interop.status.peer-created'));
    }

    public function updatePeer(Request $request, string $siteId): RedirectResponse
    {
        if ($this->repository->peer($siteId) === null) {
            abort(404);
        }

        $values = $request->validate([
            'name' => ['nullable', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:2000'],
            'domain' => ['required', 'string', 'max:253'],
            'manifest_url' => ['nullable', 'string', 'max:512'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $this->repository->savePeer($this->peerValues($values, $siteId));

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
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function peerValues(array $values, string $siteId): array    {
        $domain = InteropRules::host($values['domain'] ?? null);

        if ($domain === null) {
            throw ValidationException::withMessages([
                'domain' => trans('sitmc-launcher::launcher.interop.problem.peer-domain-invalid'),
            ]);
        }

        $origin = 'https://'.$domain;
        $manifestUrl = $this->textOrNull($values['manifest_url'] ?? null);

        if ($manifestUrl !== null && InteropRules::publicHttpsUrl($manifestUrl) === null) {
            throw ValidationException::withMessages([
                'manifest_url' => trans('sitmc-launcher::launcher.interop.problem.peer-manifest-url-invalid'),
            ]);
        }

        return [
            'site_id' => $siteId,
            'name' => $this->textOrNull($values['name'] ?? null),
            'description' => $this->textOrNull($values['description'] ?? null),
            'origin' => $origin,
            'manifest_url' => $manifestUrl ?? $origin.InteropRules::WELL_KNOWN_PATH,
            'enabled' => (bool) ($values['enabled'] ?? false),
        ];
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
