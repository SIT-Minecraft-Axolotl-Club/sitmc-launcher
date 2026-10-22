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
