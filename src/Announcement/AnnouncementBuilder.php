<?php

namespace SITMinecraft\Launcher\Announcement;

use SITMinecraft\Launcher\Support\Timestamps;

/**
 * Turns stored announcement rows into the array the launcher downloads.
 *
 * Unlike the manifest this document has no envelope: the client parses the
 * response body as an array of entries, so anything else has to be a plain list.
 * Building it is deliberately framework free — the rows are plain arrays — which
 * keeps the "what does the client actually receive" question answerable without
 * a database or a booted application.
 *
 * Ordering is decided here rather than left to the client: entries are sorted by
 * priority and publication date, then cut at {@see AnnouncementRules::MAX_ENTRIES},
 * so the entries that survive the cap are the ones players should see first.
 */
final class AnnouncementBuilder
{
    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function build(iterable $rows, ?int $now = null): array
    {
        $now ??= time();
        $entries = [];

        foreach ($rows as $row) {
            if (! (bool) ($row['enabled'] ?? true)) {
                continue;
            }

            if (AnnouncementRules::problems($row) !== []) {
                continue;
            }

            if (! $this->isActive($row, $now)) {
                continue;
            }

            $entries[] = $this->entry($row);
        }

        $rank = array_flip(AnnouncementRules::PRIORITIES);

        usort($entries, static function (array $left, array $right) use ($rank): int {
            return $rank[$right['priority']] <=> $rank[$left['priority']]
                ?: strcmp((string) $right['published_at'], (string) $left['published_at']);
        });

        return array_slice($entries, 0, AnnouncementRules::MAX_ENTRIES);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    public function encode(array $entries): string
    {
        return (string) json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Only announcements whose window covers now are handed out: the client
     * would drop the others anyway, and not sending them keeps the document
     * small enough to stay under its size limit.
     *
     * @param  array<string, mixed>  $row
     */
    private function isActive(array $row, int $now): bool
    {
        $starts = Timestamps::value($row['starts_at'] ?? null);

        if ($starts === null || $starts > $now) {
            return false;
        }

        $ends = Timestamps::value($row['ends_at'] ?? null);

        return $ends === null || $ends > $now;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function entry(array $row): array
    {
        return [
            'id' => (string) ($row['announcement_id'] ?? $row['id'] ?? ''),
            'title' => (string) $row['title'],
            'summary' => self::textOrNull($row['summary'] ?? null),
            'content' => (string) $row['content'],
            'type' => (string) $row['type'],
            'priority' => (string) $row['priority'],
            'starts_at' => Timestamps::isoUtc($row['starts_at'] ?? null),
            'ends_at' => Timestamps::isoUtc($row['ends_at'] ?? null),
            'published_at' => Timestamps::isoUtc($row['published_at'] ?? null),
            'action_url' => self::textOrNull($row['action_url'] ?? null),
            'action_label' => self::textOrNull($row['action_label'] ?? null),
        ];
    }

    private static function textOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
