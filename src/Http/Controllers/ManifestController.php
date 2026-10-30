<?php

namespace SITMinecraft\Launcher\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SITMinecraft\Launcher\Interop\InteropRepository;
use SITMinecraft\Launcher\Interop\SiteDocumentService;
use SITMinecraft\Launcher\Manifest\LauncherRepository;
use SITMinecraft\Launcher\Manifest\ManifestBuilder;

/**
 * The public endpoint the launcher polls for its instance catalog.
 *
 * The launcher downloads the document itself and never sends credentials, so the
 * endpoint is unauthenticated. It answers 304 when the caller already holds the
 * current document, and it is the only place that decides what "current" means.
 *
 * Featured instances — local ones picked in the admin, and peer instances
 * picked from synced friend sites — are forced to `sort = -1`, so the launcher
 * renders them first.
 */
final class ManifestController
{
    public function __construct(
        private LauncherRepository $repository,
        private InteropRepository $interop,
        private SiteDocumentService $documents,
    ) {}

    public function show(Request $request): Response
    {
        $builder = new ManifestBuilder($request->root());
        $settings = $this->repository->settings();

        // Featured local instances are forced to sort = -1, and featured peer
        // instances are injected into the same document, also at sort = -1,
        // so the launcher's home page renders them first.
        $featuredLocal = $this->interop->featuredLocalIds();
        $featuredPeers = $this->interop->featuredPeerIds();

        $document = $builder->buildWithFeatured(
            $this->repository->rows(),
            $featuredLocal,
            $settings['min_version'],
            $settings['latest_version'],
            $settings['force_update']
        );

        if ($featuredPeers !== []) {
            $friends = $this->documents->friends($request->root())['document'];

            foreach ($friends['sites'] ?? [] as $siteRow) {
                $siteId = (string) $siteRow['site_id'];
                $wanted = $featuredPeers[$siteId] ?? null;

                if ($wanted === null) {
                    continue;
                }

                foreach ($siteRow['instances'] ?? [] as $instance) {
                    $rawId = (string) $instance['id'];
                    $raw = str_starts_with($rawId, $siteId.':')
                        ? substr($rawId, strlen($siteId) + 1)
                        : $rawId;

                    if (! in_array($raw, $wanted, true)) {
                        continue;
                    }

                    $instance['id'] = $siteId.':'.$raw;
                    $instance['sort'] = -1;
                    $document['instances'][] = $instance;
                }
            }

            usort($document['instances'], static function (array $left, array $right): int {
                return [$left['sort'], $left['name']] <=> [$right['sort'], $right['name']];
            });
        }

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
