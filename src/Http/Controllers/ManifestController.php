<?php

namespace SITMinecraft\Launcher\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SITMinecraft\Launcher\Interop\InteropRepository;
use SITMinecraft\Launcher\Manifest\LauncherRepository;
use SITMinecraft\Launcher\Manifest\ManifestBuilder;

/**
 * The public endpoint the launcher polls for its instance catalog.
 *
 * The launcher downloads the document itself and never sends credentials, so the
 * endpoint is unauthenticated. It answers 304 when the caller already holds the
 * current document, and it is the only place that decides what "current" means.
 *
 * Local instances featured in the interop admin are forced to `sort = -1`, so
 * the launcher renders them first.
 */
final class ManifestController
{
    public function __construct(
        private LauncherRepository $repository,
        private InteropRepository $interop,
    ) {}

    public function show(Request $request): Response
    {
        $builder = new ManifestBuilder($request->root());
        $settings = $this->repository->settings();

        $featuredIds = array_map(
            static fn (array $row): string => (string) $row['instance_id'],
            array_filter(
                $this->interop->featured(),
                static fn (array $row): bool => $row['peer_site_id'] === null
            )
        );

        $document = $builder->buildWithFeatured(
            $this->repository->rows(),
            $featuredIds,
            $settings['min_version'],
            $settings['latest_version'],
            $settings['force_update']
        );

        $etag = $builder->etag($document);

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        return response($builder->encode($document), 200, [
            'Content-Type' => 'application/json',
            'ETag' => $etag,
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }
}
