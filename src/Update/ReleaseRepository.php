<?php

namespace SITMinecraft\Launcher\Update;

use Illuminate\Support\Facades\DB;
use SITMinecraft\Launcher\Database\LauncherSchema;

/**
 * Reads and writes the launcher releases this plugin owns.
 *
 * Rows are handed out newest first and unfiltered: which row applies to a given
 * channel and platform is a question about the update contract, so it is answered
 * in {@see UpdateBuilder} rather than in SQL. Keeping the query dumb means the
 * selection can be reasoned about — and tested — without a database.
 *
 * The schema is ensured on construction because a plugin that was enabled while
 * the application was already booted only gets its tables on the first request.
 */
final class ReleaseRepository
{
    public function __construct()
    {
        LauncherSchema::install();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        return DB::table(LauncherSchema::RELEASES)
            ->orderByDesc('pub_date')
            ->orderByDesc('id')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function row(int $id): ?array
    {
        $row = DB::table(LauncherSchema::RELEASES)->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        $id = (int) ($values['id'] ?? 0);
        unset($values['id']);

        if ($id > 0 && DB::table(LauncherSchema::RELEASES)->where('id', $id)->exists()) {
            DB::table(LauncherSchema::RELEASES)->where('id', $id)->update($values);

            return;
        }

        DB::table(LauncherSchema::RELEASES)->insert($values);
    }

    public function delete(int $id): void
    {
        DB::table(LauncherSchema::RELEASES)->where('id', $id)->delete();
    }

    /**
     * Table name for the admin form's uniqueness rule.
     */
    public static function table(): string
    {
        return LauncherSchema::RELEASES;
    }
}
