<?php

namespace SITMinecraft\Launcher\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SITMinecraft\Launcher\Manifest\LauncherRepository;
use SITMinecraft\Launcher\Manifest\ManifestBuilder;

/**
 * The public endpoint the launcher polls for its instance catalog.
 *
 * The launcher downloads the document itself and never sends credentials, so the
 * endpoint is unauthenticated. It answers 304 when the caller already holds the
 * current document, and it is the only place that decides what "current" means.
 */
final class ManifestController
{
    public function __construct(private LauncherRepository $repository) {}

    public function show(Request $request): Response
    {
        $builder = new ManifestBuilder($request->root());
        $settings = $this->repository->settings();

        $document = $builder->build(
            $this->repository->rows(),
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
