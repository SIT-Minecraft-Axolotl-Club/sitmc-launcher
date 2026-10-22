<?php

namespace SITMinecraft\Launcher\Announcement;

use SITMinecraft\Launcher\Support\Timestamps;

/**
 * Validation shared by the announcements endpoint and the admin editor.
 *
 * These rules mirror the launcher's remote announcement validator (see the
 * launcher repository's `apps/app-frontend/src/helpers/remote-announcements.ts`).
 * The client silently drops one entry that breaks any single rule, and discards
 * the whole document once it carries more than {@see MAX_ENTRIES} entries, so a
 * stored row that fails here must never be published. The admin screens show the
 * very same reasons, which is why the rules live in one place.
 */
final class AnnouncementRules
{
    /** Upper bound of the client validator: a longer array is discarded whole. */
    public const MAX_ENTRIES = 200;

    public const MAX_ID_LENGTH = 100;

    public const MAX_TITLE_LENGTH = 120;

    public const MAX_SUMMARY_LENGTH = 300;

    public const MAX_CONTENT_LENGTH = 20000;

    public const MAX_ACTION_LABEL_LENGTH = 80;

    public const TYPES = ['modal', 'notification'];

    public const PRIORITIES = ['low', 'normal', 'high', 'critical'];

    /**
     * Ids double as route parameters of this plugin's admin screens, so they are
     * held to a URL-safe charset. The client itself only requires a non-empty,
     * unique string, so this restriction is always safe to publish under.
     */
    public const ID_PATTERN = '[A-Za-z0-9_.-]+';

    /**
     * Every reason the launcher would reject this row, in the order the client
     * checks them. An empty result means the row can be published.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    public static function problems(array $row): array
    {
        $problems = [
            self::idProblem($row['announcement_id'] ?? $row['id'] ?? null),
            self::titleProblem($row['title'] ?? null),
            self::enumProblem('type', $row['type'] ?? null, self::TYPES),
            self::enumProblem('priority', $row['priority'] ?? null, self::PRIORITIES),
            self::timestampProblem('starts-at', $row['starts_at'] ?? null),
            self::timestampProblem('ends-at', $row['ends_at'] ?? null, false),
            self::timestampProblem('published-at', $row['published_at'] ?? null),
            self::contentProblem($row['content'] ?? null),
            self::summaryProblem($row['summary'] ?? null),
            self::actionUrlProblem($row['action_url'] ?? null),
            self::actionLabelProblem($row['action_label'] ?? null),
        ];

        return array_values(array_filter($problems, static fn (?string $problem): bool => $problem !== null));
    }

    /**
     * First reason the row cannot be published, for the compact list badge.
     *
     * @param  array<string, mixed>  $row
     */
    public static function publishProblem(array $row): ?string
    {
        return self::problems($row)[0] ?? null;
    }

    /**
     * Whether the row's active window currently covers `now`. A row can be
     * perfectly valid and still not shown, and the admin screens have to tell
     * that apart from a rule violation.
     *
     * @param  array<string, mixed>  $row
     * @return 'scheduled'|'active'|'expired'
     */
    public static function window(array $row, ?int $now = null): string
    {
        $now ??= time();
        $starts = Timestamps::value($row['starts_at'] ?? null);

        if ($starts !== null && $starts > $now) {
            return 'scheduled';
        }

        $ends = Timestamps::value($row['ends_at'] ?? null);

        return $ends !== null && $ends <= $now ? 'expired' : 'active';
    }

    /**
     * The client compares lengths in JavaScript, so length means characters, not
     * bytes: a 120 character Chinese title is long enough to be rejected but far
     * longer than 120 bytes.
     */
    public static function length(mixed $value): int
    {
        return mb_strlen((string) $value, 'UTF-8');
    }

    public static function idProblem(mixed $id): ?string
    {
        $id = (string) $id;

        if (trim($id) === '') {
            return 'announcement-id-empty';
        }

        return self::length($id) > self::MAX_ID_LENGTH ? 'announcement-id-too-long' : null;
    }

    public static function titleProblem(mixed $title): ?string
    {
        $title = (string) $title;

        if (trim($title) === '') {
            return 'announcement-title-empty';
        }

        return self::length($title) > self::MAX_TITLE_LENGTH ? 'announcement-title-too-long' : null;
    }

    public static function summaryProblem(mixed $summary): ?string
    {
        if ($summary === null) {
            return null;
        }

        return self::length($summary) > self::MAX_SUMMARY_LENGTH ? 'announcement-summary-too-long' : null;
    }

    public static function contentProblem(mixed $content): ?string
    {
        if ($content === null) {
            return 'announcement-content-missing';
        }

        return self::length($content) > self::MAX_CONTENT_LENGTH ? 'announcement-content-too-long' : null;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    public static function enumProblem(string $field, mixed $value, array $allowed): ?string
    {
        return in_array((string) $value, $allowed, true) ? null : $field.'-invalid';
    }

    /**
     * A required timestamp has to be present and parseable; an optional one may
     * be absent, but not malformed.
     */
    public static function timestampProblem(string $field, mixed $value, bool $required = true): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return $required ? $field.'-missing' : null;
        }

        return Timestamps::value($value) === null ? $field.'-invalid' : null;
    }

    public static function actionUrlProblem(mixed $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return 'action-url-scheme';
        }

        if (parse_url($url, PHP_URL_HOST) === null) {
            return 'action-url-host';
        }

        return parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
            ? 'action-url-userinfo'
            : null;
    }

    public static function actionLabelProblem(mixed $label): ?string
    {
        if ($label === null) {
            return null;
        }

        return self::length($label) > self::MAX_ACTION_LABEL_LENGTH ? 'action-label-too-long' : null;
    }
}
