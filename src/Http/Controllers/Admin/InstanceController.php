<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use SITMinecraft\Launcher\Manifest\LauncherRepository;
use SITMinecraft\Launcher\Manifest\ExternalPackProbe;
use SITMinecraft\Launcher\Manifest\ManifestBuilder;
use SITMinecraft\Launcher\Manifest\ManifestRules;
use SITMinecraft\Launcher\Manifest\PackStore;

/**
 * Admin screens for the launcher catalog.
 *
 * Publishing is deliberately revision based: editing an instance only changes
 * what clients display, while a new pack upload (or an explicit activation of an
 * older revision) is what forces every client to reinstall.
 */
final class InstanceController
{
    public function __construct(
        private LauncherRepository $repository,
        private PackStore $packs,
        private ExternalPackProbe $probe,
        private \SITMinecraft\Launcher\Interop\InteropRepository $interop,
        private \SITMinecraft\Launcher\Interop\SiteDocumentService $documents,
    ) {}

    public function index(Request $request): View
    {
        $builder = new ManifestBuilder($request->root());

        $featured = array_fill_keys(array_map(
            static fn (array $row): string => (string) $row['instance_id'],
            array_filter(
                $this->interop->featured(),
                static fn (array $row): bool => $row['peer_site_id'] === null
            )
        ), true);

        $instances = [];

        foreach ($this->repository->rows() as $row) {
            $row['problem'] = ManifestRules::publishProblem($row, $builder->packUrlFor($row));
            $row['pack_url_resolved'] = $builder->packUrlFor($row);
            $row['featured'] = isset($featured[(string) $row['instance_id']]);
            $instances[] = $row;
        }

        return view('sitmc-launcher::admin.instances', [
            'instances' => $instances,
            'settings' => $this->repository->settings(),
            'limits' => $this->uploadLimits(),
            'manifestPath' => '/api/launcher/manifest',
            'peerFeatured' => $this->documents->friends($request->root())['document'],
            'peerFeaturedKeys' => array_fill_keys(array_map(
                static fn (array $row): string => $row['peer_site_id'].':'.$row['instance_id'],
                array_filter(
                    $this->interop->featured(),
                    static fn (array $row): bool => $row['peer_site_id'] !== null
                )
            ), true),
            'status' => session('sitmc_launcher_status'),
        ]);
    }

    public function create(): View
    {
        return view('sitmc-launcher::admin.instance', [
            'instance' => null,
            'packs' => [],
            'limits' => $this->uploadLimits(),
            'status' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $values = $this->validated($request);

        $this->repository->saveInstance($values + [
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()
            ->route('sitmc-launcher.admin.edit', $values['instance_id'])
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.created'));
    }

    public function edit(Request $request, string $instanceId): View
    {
        $row = $this->repository->row($instanceId);

        if ($row === null) {
            abort(404);
        }

        $builder = new ManifestBuilder($request->root());
        $row['problem'] = ManifestRules::publishProblem($row, $builder->packUrlFor($row));
        $row['pack_url_resolved'] = $builder->packUrlFor($row);

        return view('sitmc-launcher::admin.instance', [
            'instance' => $row,
            'packs' => $this->repository->packs($instanceId),
            'limits' => $this->uploadLimits(),
            'status' => session('sitmc_launcher_status'),
        ]);
    }

    public function update(Request $request, string $instanceId): RedirectResponse
    {
        $this->requireInstance($instanceId);

        $values = $this->validated($request, $instanceId);
        $values['updated_at'] = now();

        $this->repository->saveInstance($values);

        return redirect()
            ->route('sitmc-launcher.admin.edit', $instanceId)
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.saved'));
    }

    public function destroy(string $instanceId): RedirectResponse
    {
        foreach ($this->repository->packs($instanceId) as $pack) {
            $this->packs->forget($pack['disk_path'] ?? null);
        }

        $this->repository->deleteInstance($instanceId);

        return redirect()
            ->route('sitmc-launcher.admin.index')
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.deleted'));
    }

    public function storePack(Request $request, string $instanceId): RedirectResponse
    {
        $this->requireInstance($instanceId);

        $request->validate(
            ['pack' => ['required', 'file', 'max:'.$this->maxUploadKilobytes()]],
            ['pack.max' => trans('sitmc-launcher::launcher.packs.too-large')]
        );

        $revision = $this->nextRevision($instanceId);

        try {
            $stored = $this->packs->put($request->file('pack'), $instanceId, $revision);
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'pack' => trans('sitmc-launcher::launcher.packs.error.'.$exception->getMessage()),
            ]);
        }

        $this->repository->savePack($instanceId, $revision, [
            'disk_path' => $stored['disk_path'],
            'original_name' => $stored['original_name'],
            'sha1' => $stored['sha1'],
            'size' => $stored['size'],
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        $this->repository->setRevision($instanceId, $revision);

        return redirect()
            ->route('sitmc-launcher.admin.edit', $instanceId)
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.pack-uploaded', [
                'revision' => $revision,
            ]));
    }

    public function storeExternalPack(Request $request, string $instanceId): RedirectResponse
    {
        $this->requireInstance($instanceId);

        $values = $request->validate([
            'url' => ['required', 'string', 'max:512'],
            // Externally hosted packs may omit the digest: when left empty the
            // server probes the URL and computes SHA-1 / size automatically.
            // The probe is best-effort; a failed probe stores the URL as-is.
            'sha1' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{40}$/'],
            'size' => ['nullable', 'integer', 'min:1'],
        ]);

        $problem = ManifestRules::packUrlProblem($values['url']);

        if ($problem !== null) {
            return back()->withErrors([
                'url' => trans('sitmc-launcher::launcher.problem.'.$problem),
            ]);
        }

        $sha1 = isset($values['sha1']) ? strtolower($values['sha1']) : null;
        $size = $values['size'] ?? null;

        if ($sha1 === null || $size === null) {
            $probed = $this->probe->probe($values['url']);

            if ($probed['ok']) {
                $sha1 ??= $probed['sha1'] ?? null;
                $size ??= $probed['size'] ?? null;
            }
        }

        $revision = $this->nextRevision($instanceId);

        $this->repository->savePack($instanceId, $revision, [
            'url' => $values['url'],
            'sha1' => $sha1,
            'size' => $size ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->repository->setRevision($instanceId, $revision);

        return redirect()
            ->route('sitmc-launcher.admin.edit', $instanceId)
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.pack-uploaded', [
                'revision' => $revision,
            ]));
    }

    public function activatePack(string $instanceId, int $revision): RedirectResponse
    {
        $this->requireInstance($instanceId);

        if ($this->repository->pack($instanceId, $revision) === null) {
            abort(404);
        }

        $this->repository->setRevision($instanceId, $revision);

        return redirect()
            ->route('sitmc-launcher.admin.edit', $instanceId)
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.pack-activated', [
                'revision' => $revision,
            ]));
    }

    public function destroyPack(string $instanceId, int $revision): RedirectResponse
    {
        $this->requireInstance($instanceId);

        $pack = $this->repository->pack($instanceId, $revision);

        if ($pack === null) {
            abort(404);
        }

        $this->packs->forget($pack['disk_path'] ?? null);
        $this->repository->deletePack($instanceId, $revision);

        if ((int) ($this->repository->row($instanceId)['revision'] ?? 0) === $revision) {
            $this->repository->setRevision($instanceId, $this->repository->highestRevision($instanceId));
        }

        return redirect()
            ->route('sitmc-launcher.admin.edit', $instanceId)
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.pack-deleted'));
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'min_version' => ['nullable', 'string', 'max:32'],
            'latest_version' => ['nullable', 'string', 'max:32'],
            'force_update' => ['nullable', 'boolean'],
        ]);

        $this->repository->saveSettings([
            'min_version' => $values['min_version'] ?? null,
            'latest_version' => $values['latest_version'] ?? null,
            'force_update' => (bool) ($values['force_update'] ?? false),
        ]);

        return redirect()
            ->route('sitmc-launcher.admin.index')
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.settings-saved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?string $instanceId = null): array
    {
        $unique = 'unique:'.LauncherRepository::instanceTable().',instance_id';

        if ($instanceId !== null) {
            $unique .= ','.$instanceId.',instance_id';
        }

        $values = $request->validate([
            'instance_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/', $unique],
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon_url' => ['nullable', 'string', 'max:512'],
            'sort' => ['nullable', 'integer', 'min:-100000', 'max:100000'],
            'game_version' => ['required', 'string', 'max:64'],
            'loader' => ['nullable', 'string', 'max:32'],
            'loader_version' => ['nullable', 'string', 'max:64'],
            'server_address' => ['nullable', 'string', 'max:255'],
            'server_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'required' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        return [
            'instance_id' => $values['instance_id'],
            'name' => $values['name'],
            'description' => $values['description'] ?? null,
            'icon_url' => $values['icon_url'] ?? null,
            'sort' => (int) ($values['sort'] ?? 0),
            'game_version' => $values['game_version'],
            'loader' => $values['loader'] ?? null,
            'loader_version' => $values['loader_version'] ?? null,
            'server_address' => $values['server_address'] ?? null,
            'server_port' => $values['server_port'] ?? null,
            'required' => (bool) ($values['required'] ?? false),
            'enabled' => (bool) ($values['enabled'] ?? false),
        ];
    }

    private function requireInstance(string $instanceId): void
    {
        if ($this->repository->row($instanceId) === null) {
            abort(404);
        }
    }

    private function nextRevision(string $instanceId): int
    {
        return max(
            $this->repository->highestRevision($instanceId) + 1,
            (int) ($this->repository->row($instanceId)['revision'] ?? 1) + 1,
            1
        );
    }

    /**
     * Saves the featured peer-instance selection. Peer keys are validated
     * against the current friend snapshots; local keys are preserved.
     */
    public function savePeerFeatured(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'featured' => ['nullable', 'array'],
            'featured.*' => ['string', 'max:200'],
        ]);

        $wanted = array_fill_keys(array_map('strval', $values['featured'] ?? []), true);
        $friends = $this->documents->friends($request->root())['document'];

        $valid = [];

        foreach ($friends['sites'] ?? [] as $siteRow) {
            foreach ($siteRow['instances'] ?? [] as $instance) {
                $key = $siteRow['site_id'].':'.(string) $instance['id'];

                if (isset($wanted[$key])) {
                    $valid[] = $key;
                }
            }
        }

        // Keep existing local selections, replace the peer ones.
        $local = array_map(
            static fn (array $row): string => 'local:'.$row['instance_id'],
            array_filter(
                $this->interop->featured(),
                static fn (array $row): bool => $row['peer_site_id'] === null
            )
        );

        $this->interop->saveFeatured(array_merge($local, $valid));

        return redirect()
            ->route('sitmc-launcher.admin.index')
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.interop.status.featured-saved', [
                'count' => count($valid),
            ]));
    }

    /**
     * Toggles the local featured flag for one instance. Featured instances get
     * `sort = -1` in the launcher manifest.
     */
    public function toggleFeatured(string $instanceId): RedirectResponse
    {
        if ($this->repository->row($instanceId) === null) {
            abort(404);
        }

        $current = array_filter(
            $this->interop->featured(),
            static fn (array $row): bool => $row['peer_site_id'] === null
        );

        $keep = [];
        $found = false;

        foreach ($current as $row) {
            if ($row['instance_id'] === $instanceId) {
                $found = true;

                continue;
            }

            $keep[] = 'local:'.$row['instance_id'];
        }

        if (! $found) {
            $keep[] = 'local:'.$instanceId;
        }

        $this->interop->saveFeatured($keep);

        return redirect()
            ->route('sitmc-launcher.admin.index')
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.' . ($found ? 'featured-off' : 'featured-on')));
    }

    /**
     * @return array{max_upload: string, max_post: string, max_kilobytes: int}
     */
    private function uploadLimits(): array
    {
        return [
            'max_upload' => (string) ini_get('upload_max_filesize'),
            'max_post' => (string) ini_get('post_max_size'),
            'max_kilobytes' => $this->maxUploadKilobytes(),
        ];
    }

    /**
     * Largest upload the current PHP configuration accepts, in kilobytes, so the
     * validation rule fails with a message that matches the server's real limit.
     */
    private function maxUploadKilobytes(): int
    {
        $toBytes = static function (string $value): int {
            $value = trim($value);
            $unit = strtolower(substr($value, -1));
            $number = (int) $value;

            return match ($unit) {
                'g' => $number * 1024 * 1024 * 1024,
                'm' => $number * 1024 * 1024,
                'k' => $number * 1024,
                default => $number,
            };
        };

        $upload = $toBytes((string) ini_get('upload_max_filesize'));
        $post = $toBytes((string) ini_get('post_max_size'));
        $candidates = array_filter(
            [$upload, $post],
            static fn (int $bytes): bool => $bytes > 0
        );

        $limit = $candidates === [] ? 2 * 1024 * 1024 : min($candidates);

        return max(1, intdiv($limit, 1024));
    }
}
