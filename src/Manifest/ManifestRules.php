<?php

namespace SITMinecraft\Launcher\Manifest;

/**
 * Validation shared by the manifest endpoint and the admin editor.
 *
 * These rules mirror what the launcher checks before it accepts a manifest
 * (see the launcher repository's `docs/launcher-protocol.md`). A row that fails
 * here is never published, because publishing it would make every client fail to
 * install that instance.
 */
final class ManifestRules
{
    public const SCHEMA = 1;

    public const PACK_KIND = 'mrpack';

    public const MAX_ID_LENGTH = 64;

    /**
     * Returns the reason an instance id cannot be published, or null when it can.
     *
     * The launcher refuses any id that is empty, longer than 64 bytes, or that
     * uses a character outside `[A-Za-z0-9_-]`, so the same charset is enforced
     * here.
     */
    public static function idProblem(string $id): ?string
    {
        if ($id === '') {
            return 'id-empty';
        }

        if (strlen($id) > self::MAX_ID_LENGTH) {
            return 'id-too-long';
        }

        if (preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1) {
            return 'id-charset';
        }

        return null;
    }

    /**
     * Returns the reason a revision cannot be published, or null when it can.
     */
    public static function revisionProblem(int $revision): ?string
    {
        return $revision < 0 ? 'revision-negative' : null;
    }

    /**
     * Normalises a SHA-1 digest to the lowercase hex form the launcher compares
     * against, or returns null when the value is not a usable digest.
     */
    public static function normalizeSha1(?string $sha1): ?string
    {
        $sha1 = strtolower(trim((string) $sha1));

        return preg_match('/^[0-9a-f]{40}$/', $sha1) === 1 ? $sha1 : null;
    }

    /**
     * Returns the reason a pack URL cannot be used, or null when it can.
     *
     * The launcher downloads the pack itself, so the URL has to be absolute and
     * use http or https.
     */
    public static function packUrlProblem(string $url): ?string
    {
        if ($url === '') {
            return 'pack-url-empty';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return 'pack-url-scheme';
        }

        return parse_url($url, PHP_URL_HOST) ? null : 'pack-url-host';
    }

    /**
     * Returns the reason a row cannot be published, or null when it can.
     *
     * @param  array<string, mixed>  $row
     */
    public static function publishProblem(array $row, string $packUrl): ?string
    {
        return self::idProblem((string) ($row['id'] ?? ''))
            ?? self::revisionProblem((int) ($row['revision'] ?? 0))
            ?? ((string) ($row['game_version'] ?? '') === '' ? 'game-version-empty' : null)
            ?? (self::normalizeSha1($row['pack_sha1'] ?? null) === null ? 'pack-sha1-missing' : null)
            ?? self::packUrlProblem($packUrl);
    }
}
