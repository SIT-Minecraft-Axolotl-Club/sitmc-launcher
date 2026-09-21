<?php

namespace SITMinecraft\Launcher\Update;

use SITMinecraft\Launcher\Support\Timestamps;

/**
 * Validation shared by the update endpoints and the admin editor.
 *
 * A release row feeds two very different consumers, so the rules are split:
 * {@see metadataProblem()} covers what `/update/latest` needs (a channel, a
 * version and a publication date the launcher can parse) while
 * {@see artifactProblem()} covers what the dynamic updater additionally needs
 * (a downloadable artifact and the minisign signature that makes it installable).
 * {@see problems()} merges both for the admin screen's summary.
 */
final class ReleaseRules
{
    public const CHANNELS = ['release', 'beta'];

    public const DEFAULT_CHANNEL = 'release';

    /**
     * Semantic version, optionally with a pre-release suffix. Build metadata is
     * not accepted because the launcher never compares it.
     */
    public const VERSION_PATTERN = '\d+(\.\d+)*(-[0-9A-Za-z.-]+)?';

    /**
     * Target and architecture come from the Tauri updater URL template, where the
     * target is a triple such as `windows-x86_64`.
     */
    public const PLATFORM_PATTERN = '[A-Za-z0-9_.-]+';

    public const MAX_NOTES_LENGTH = 20000;

    /**
     * Reasons the release cannot be published as update metadata at all.
     *
     * @param  array<string, mixed>  $row
     */
    public static function metadataProblem(array $row): ?string
    {
        return self::channelProblem($row['channel'] ?? null)
            ?? self::versionProblem($row['version'] ?? null)
            ?? self::timestampProblem('pub-date', $row['pub_date'] ?? null);
    }

    /**
     * Reasons the dynamic updater document cannot be built, on top of the
     * metadata rules. The signature is what the launcher verifies before it
     * replaces its own binary, so an unsigned artifact is never offered.
     *
     * @param  array<string, mixed>  $row
     */
    public static function artifactProblem(array $row): ?string
    {
        $url = trim((string) ($row['url'] ?? ''));

        if (($problem = self::artifactUrlProblem($url)) !== null) {
            return $problem;
        }

        return trim((string) ($row['signature'] ?? '')) === ''
            ? 'release-signature-missing'
            : null;
    }

    /**
     * Every reason the row is incomplete, in the order the admin should fix them.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    public static function problems(array $row): array
    {
        $problems = array_filter([
            self::channelProblem($row['channel'] ?? null),
            self::versionProblem($row['version'] ?? null),
            self::timestampProblem('pub-date', $row['pub_date'] ?? null),
            self::artifactUrlProblem($row['url'] ?? null),
            self::signatureProblem($row),
            self::notesProblem($row['notes'] ?? null),
        ], static fn (?string $problem): bool => $problem !== null);

        return array_values($problems);
    }

    public static function channelProblem(mixed $channel): ?string
    {
        return in_array((string) $channel, self::CHANNELS, true) ? null : 'release-channel-invalid';
    }

    public static function versionProblem(mixed $version): ?string
    {
        $version = trim((string) $version);

        if ($version === '') {
            return 'release-version-missing';
        }

        return preg_match('/^'.self::VERSION_PATTERN.'$/', $version) === 1
            ? null
            : 'release-version-invalid';
    }

    /**
     * The launcher downloads the artifact itself, so the URL has to be absolute
     * and use http or https.
     */
    public static function artifactUrlProblem(mixed $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return 'release-url-missing';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return 'release-url-scheme';
        }

        return parse_url($url, PHP_URL_HOST) === null ? 'release-url-host' : null;
    }

    /**
     * A signature is only meaningful next to an artifact, so it is required as
     * soon as the URL is set and ignored when it is not.
     *
     * @param  array<string, mixed>  $row
     */
    public static function signatureProblem(array $row): ?string
    {
        if (trim((string) ($row['url'] ?? '')) === '') {
            return null;
        }

        return trim((string) ($row['signature'] ?? '')) === '' ? 'release-signature-missing' : null;
    }

    public static function notesProblem(mixed $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        return mb_strlen((string) $notes, 'UTF-8') > self::MAX_NOTES_LENGTH
            ? 'release-notes-too-long'
            : null;
    }

    public static function timestampProblem(string $field, mixed $value, bool $required = true): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return $required ? $field.'-missing' : null;
        }

        return Timestamps::value($value) === null ? $field.'-invalid' : null;
    }
}
