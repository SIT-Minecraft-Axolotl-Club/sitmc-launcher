<?php

namespace SITMinecraft\Launcher\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use SITMinecraft\Launcher\Http\PackStreamResponse;
use SITMinecraft\Launcher\Manifest\LauncherRepository;
use SITMinecraft\Launcher\Manifest\PackStore;

/**
 * Serves an uploaded pack at the URL the manifest advertises.
 *
 * Rows that point at an external URL are redirected to it instead, so an
 * administrator can move large packs to object storage without changing the
 * launcher contract.
 */
final class PackController
{
    public function __construct(
        private LauncherRepository $repository,
        private PackStore $packs,
    ) {}

    public function show(string $instanceId, int $revision): Response|RedirectResponse
    {
        $pack = $this->repository->pack($instanceId, $revision);

        if ($pack === null) {
            abort(404);
        }

        $external = trim((string) ($pack['url'] ?? ''));

        if ($external !== '') {
            return redirect()->away($external);
        }

        $diskPath = trim((string) ($pack['disk_path'] ?? ''));
        $name = sprintf('%s-%d.mrpack', $instanceId, $revision);

        if ($diskPath === '') {
            abort(404);
        }

        $absolute = $this->packs->absolutePath($diskPath);

        if ($absolute !== null) {
            return new PackStreamResponse($absolute, $name);
        }

        $contents = $this->packs->contents($diskPath);

        if ($contents === null) {
            abort(404);
        }

        return response($contents, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
