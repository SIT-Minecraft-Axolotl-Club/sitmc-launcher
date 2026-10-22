<?php

namespace SITMinecraft\Launcher\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SITMinecraft\Launcher\Update\ReleaseRepository;
use SITMinecraft\Launcher\Update\UpdateBuilder;

/**
 * The public endpoints the launcher asks about its own updates.
 *
 * `latest` only tells players that a newer build exists, while `dynamic` is the
 * document a Tauri v2 dynamic updater installs from. Both answer 204 No Content
 * when they have nothing to hand out, which the launcher reads as "no update"
 * rather than as an error.
 */
final class UpdateController
{
    public function __construct(private ReleaseRepository $repository) {}

    public function latest(Request $request): Response
    {
        $builder = new UpdateBuilder();
        $document = $builder->latest(
            $this->repository->rows(),
            self::channel($request)
        );

        return $document === null
            ? response()->noContent()
            : self::json($builder->encode($document));
    }

    /**
     * Dynamic updater document of the newest release that applies to the
     * requested platform, or 204 when the installed build is already up to date.
     *
     * The URL template lives on the administrator's side: the launcher replaces
     * `{{target}}`, `{{arch}}` and `{{current_version}}`, so nothing here has to
     * know which platforms exist.
     */
    public function dynamic(
        Request $request,
        string $target,
        string $arch,
        string $currentVersion
    ): Response {
        $builder = new UpdateBuilder();
        $document = $builder->dynamic(
            $this->repository->rows(),
            $target,
            $arch,
            $currentVersion,
            self::channel($request)
        );

        return $document === null
            ? response()->noContent()
            : self::json($builder->encode($document));
    }

    /**
     * The launcher's updater names its channel in a header, because the dynamic
     * endpoint's path carries the platform triple; the settings screen asks with
     * a query parameter instead. Both call the release channel `release` or
     * `stable`.
     */
    private static function channel(Request $request): ?string
    {
        return self::queryString($request, 'channel')
            ?? self::headerString($request, 'X-Axolotl-Channel');
    }

    /**
     * Query values arrive as arrays when a caller repeats a parameter, and the
     * builder only accepts a scalar channel.
     */
    private static function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }

    private static function headerString(Request $request, string $key): ?string
    {
        $value = $request->header($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function json(string $body): Response
    {
        return response($body, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }
}
