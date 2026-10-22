<?php

namespace SITMinecraft\Launcher\Update;

use SITMinecraft\Launcher\Support\Timestamps;

/**
 * Turns stored release rows into the two update documents the launcher asks for.
 *
 * `/update/latest` is only used to tell players that a newer build exists, so it
 * needs nothing but a version and a date. The dynamic updater document is the one
 * Tauri installs from, so it additionally carries the artifact URL and its
 * minisign signature — and only a row that has both may answer it.
 *
 * Two fallbacks are deliberate. An unknown channel falls back to `release`, and a
 * version that cannot be parsed lets the update through instead of answering "no
 * update": a player stuck on an old build is worse than an offered reinstall.
 */
final class UpdateBuilder
{
    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return array{version: string, notes: ?string, pub_date: ?string, published_at: ?string}|null
     */
    public function latest(iterable $rows, ?string $channel): ?array
    {
        $row = $this->pick($rows, self::normalizeChannel($channel), null, null, false);

        return $row === null ? null : $this->summary($row);
    }

    /**
     * The document installed by the Tauri v2 dynamic updater, or null when
     * nothing applies — which the controller answers with 204.
     *
     * The launcher names the channel it follows, because the dynamic endpoint's
     * path is already taken by the platform triple. Without a channel every
     * channel is considered, newest first, which is what an older client asks for.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    public function dynamic(
        iterable $rows,
        string $target,
        string $arch,
        string $currentVersion,
        ?string $channel = null
    ): ?array {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);

        $channels = $channel === null || trim($channel) === ''
            ? ReleaseRules::CHANNELS
            : [self::normalizeChannel($channel)];

        foreach ($channels as $candidate) {
            $row = $this->pick($rows, $candidate, $target, $arch, true);

            if ($row === null) {
                continue;
            }

            // The newest release of the channel decides: if it is not newer than
            // the installed build there is nothing to install, and falling back
            // to the other channel would push an older build onto players.
            return SemanticVersion::isNewer((string) $row['version'], $currentVersion) === false
                ? null
                : $this->document($row);
        }

        return null;
    }

    public static function normalizeChannel(?string $channel): string
    {
        $channel = strtolower(trim((string) $channel));

        return in_array($channel, ReleaseRules::CHANNELS, true)
            ? $channel
            : ReleaseRules::DEFAULT_CHANNEL;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function encode(array $document): string
    {
        return (string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Newest row of one channel that satisfies the rules, applies to the
     * requested platform, and — for the dynamic document — carries a signature.
     *
     * A row that does not name a target or architecture applies to every
     * platform, so the newest applicable row wins; that is what keeps a single
     * generic release usable without a row per platform triple.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function pick(
        iterable $rows,
        string $channel,
        ?string $target,
        ?string $arch,
        bool $requireArtifact
    ): ?array {
        $best = null;

        foreach ($rows as $row) {
            if (! (bool) ($row['enabled'] ?? true)) {
                continue;
            }

            if ((string) ($row['channel'] ?? '') !== $channel) {
                continue;
            }

            if (ReleaseRules::metadataProblem($row) !== null) {
                continue;
            }

            if ($requireArtifact && ReleaseRules::artifactProblem($row) !== null) {
                continue;
            }

            if ($target !== null && ! self::appliesTo($row, $target, $arch)) {
                continue;
            }

            if (self::isNewerThan($row, $best)) {
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * Whether a row may answer for the requested platform. An empty target or
     * architecture in the row means "every one".
     *
     * @param  array<string, mixed>  $row
     */
    private static function appliesTo(array $row, string $target, string $arch): bool
    {
        $rowTarget = strtolower(trim((string) ($row['target'] ?? '')));
        $rowArch = strtolower(trim((string) ($row['arch'] ?? '')));

        if ($rowTarget !== '' && $rowTarget !== strtolower(trim($target))) {
            return false;
        }

        return $rowArch === '' || $rowArch === strtolower(trim($arch));
    }

    /**
     * @param  array<string, mixed>|null  $current
     */
    private static function isNewerThan(array $candidate, ?array $current): bool
    {
        if ($current === null) {
            return true;
        }

        $left = Timestamps::value($candidate['pub_date'] ?? null) ?? 0;
        $right = Timestamps::value($current['pub_date'] ?? null) ?? 0;

        return $left === $right
            ? (int) ($candidate['id'] ?? 0) > (int) ($current['id'] ?? 0)
            : $left > $right;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{version: string, notes: ?string, pub_date: ?string, published_at: ?string}
     */
    private function summary(array $row): array
    {
        $publishedAt = Timestamps::isoUtc($row['pub_date'] ?? null);

        return [
            'version' => (string) $row['version'],
            'notes' => self::textOrNull($row['notes'] ?? null),
            'pub_date' => $publishedAt,
            // The launcher's update client reads `published_at` — the field the
            // upstream update service used — to decide whether a release is past
            // its release delay. Serving it next to the Tauri field keeps old
            // installed builds working against this service.
            'published_at' => $publishedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function document(array $row): array
    {
        return $this->summary($row) + [
            'url' => trim((string) $row['url']),
            'signature' => trim((string) $row['signature']),
        ];
    }

    private static function textOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
