<?php

namespace SITMinecraft\Launcher\Announcement;

use Illuminate\Support\Facades\DB;
use SITMinecraft\Launcher\Database\LauncherSchema;

/**
 * Reads and writes the announcements this plugin owns.
 *
 * The repository hands out plain arrays so the document builder stays free of
 * the database, and it normalises the stored `announcement_id` into the `id` the
 * launcher contract uses, exactly like the instance repository does.
 *
 * The schema is ensured on construction because a plugin that was enabled while
 * the application was already booted only gets its tables on the first request.
 */
final class AnnouncementRepository
{
    public function __construct()
    {
        LauncherSchema::install();
    }

    /**
     * Newest announcement first, which is the order the admin list wants.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        return DB::table(LauncherSchema::ANNOUNCEMENTS)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get()
            ->map(static fn ($row): array => self::normalize($row))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function row(string $announcementId): ?array
    {
        $row = DB::table(LauncherSchema::ANNOUNCEMENTS)
            ->where('announcement_id', $announcementId)
            ->first();

        return $row === null ? null : self::normalize($row);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        $id = (string) $values['announcement_id'];

        DB::table(LauncherSchema::ANNOUNCEMENTS)->updateOrInsert(
            ['announcement_id' => $id],
            $values
        );
    }

    public function delete(string $announcementId): void
    {
        DB::table(LauncherSchema::ANNOUNCEMENTS)->where('announcement_id', $announcementId)->delete();
    }

    /**
     * Table name for the admin form's uniqueness rule.
     */
    public static function table(): string
    {
        return LauncherSchema::ANNOUNCEMENTS;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalize(object|array $row): array
    {
        $row = (array) $row;
        $row['id'] = (string) ($row['announcement_id'] ?? '');

        return $row;
    }
}
