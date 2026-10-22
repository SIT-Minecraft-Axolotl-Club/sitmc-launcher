<?php

namespace SITMinecraft\Launcher\Manifest;

use Illuminate\Support\Facades\DB;
use SITMinecraft\Launcher\Database\LauncherSchema;

/**
 * Reads and writes the tables this plugin owns.
 *
 * The repository returns plain arrays in the shape {@see ManifestBuilder}
 * expects, so the manifest document never depends on how the rows are stored.
 * Every entry point resolves this class before it touches the database, which is
 * why the schema is ensured here: a plugin that was enabled while the app was
 * already booted still gets its tables on the first request.
 */
final class LauncherRepository
{
    private const SETTING_KEYS = ['min_version', 'latest_version', 'force_update'];

    public function __construct()
    {
        LauncherSchema::install();
    }

    /**
     * Every instance joined with the pack of its current revision.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        return $this->instanceQuery()
            ->orderBy('i.sort')
            ->orderBy('i.name')
            ->get(self::ROW_COLUMNS)
            ->map(static fn ($row): array => self::normalize($row))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function row(string $instanceId): ?array
    {
        $row = $this->instanceQuery()
            ->where('i.instance_id', $instanceId)
            ->first(self::ROW_COLUMNS);

        return $row === null ? null : self::normalize($row);
    }

    /**
     * Launcher update policy published alongside the instances.
     *
     * @return array{min_version: ?string, latest_version: ?string, force_update: bool}
     */
    public function settings(): array
    {
        $stored = DB::table(LauncherSchema::SETTINGS)
            ->whereIn('name', self::SETTING_KEYS)
            ->pluck('value', 'name');

        $text = static function (string $key) use ($stored): ?string {
            $value = trim((string) ($stored[$key] ?? ''));

            return $value === '' ? null : $value;
        };

        return [
            'min_version' => $text('min_version'),
            'latest_version' => $text('latest_version'),
            'force_update' => ($stored['force_update'] ?? null) === '1',
        ];
    }

    /**
     * @param  array{min_version?: ?string, latest_version?: ?string, force_update?: bool}  $values
     */
    public function saveSettings(array $values): void
    {
        $rows = [
            'min_version' => trim((string) ($values['min_version'] ?? '')),
            'latest_version' => trim((string) ($values['latest_version'] ?? '')),
            'force_update' => ($values['force_update'] ?? false) ? '1' : '0',
        ];

        foreach ($rows as $name => $value) {
            DB::table(LauncherSchema::SETTINGS)->updateOrInsert(
                ['name' => $name],
                ['value' => $value]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function saveInstance(array $values): void
    {
        $id = (string) $values['instance_id'];
        $existing = DB::table(LauncherSchema::INSTANCES)->where('instance_id', $id)->exists();

        if ($existing) {
            DB::table(LauncherSchema::INSTANCES)->where('instance_id', $id)->update($values);
        } else {
            DB::table(LauncherSchema::INSTANCES)->insert($values);
        }
    }

    public function deleteInstance(string $instanceId): void
    {
        DB::table(LauncherSchema::PACKS)->where('instance_id', $instanceId)->delete();
        DB::table(LauncherSchema::INSTANCES)->where('instance_id', $instanceId)->delete();
    }

    /**
     * Pack history of one instance, newest revision first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function packs(string $instanceId): array
    {
        return DB::table(LauncherSchema::PACKS)
            ->where('instance_id', $instanceId)
            ->orderByDesc('revision')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pack(string $instanceId, int $revision): ?array
    {
        $row = DB::table(LauncherSchema::PACKS)
            ->where('instance_id', $instanceId)
            ->where('revision', $revision)
            ->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function savePack(string $instanceId, int $revision, array $values): void
    {
        $values['instance_id'] = $instanceId;
        $values['revision'] = $revision;

        DB::table(LauncherSchema::PACKS)->updateOrInsert(
            ['instance_id' => $instanceId, 'revision' => $revision],
            $values
        );
    }

    public function deletePack(string $instanceId, int $revision): void
    {
        DB::table(LauncherSchema::PACKS)
            ->where('instance_id', $instanceId)
            ->where('revision', $revision)
            ->delete();
    }

    /**
     * Highest revision the instance has ever published, so an edit can bump it.
     */
    public function highestRevision(string $instanceId): int
    {
        return (int) DB::table(LauncherSchema::PACKS)
            ->where('instance_id', $instanceId)
            ->max('revision');
    }

    /**
     * Publishes one revision as the current one. Clients compare this value with
     * the revision they installed, so changing it is what forces an update.
     */
    public function setRevision(string $instanceId, int $revision): void
    {
        DB::table(LauncherSchema::INSTANCES)
            ->where('instance_id', $instanceId)
            ->update(['revision' => max(0, $revision), 'updated_at' => now()]);
    }

    public static function instanceTable(): string
    {
        return LauncherSchema::INSTANCES;
    }

    private function instanceQuery()
    {
        return DB::table(LauncherSchema::INSTANCES.' as i')->leftJoin(
            LauncherSchema::PACKS.' as p',
            static function ($join): void {
                $join->on('p.instance_id', '=', 'i.instance_id')
                    ->on('p.revision', '=', 'i.revision');
            }
        );
    }

    /**
     * Adds the manifest's own key for an instance. The manifest contract calls it
     * `id`, while the table (and the admin screens) call it `instance_id`, so both
     * names have to be present after a read.
     *
     * @return array<string, mixed>
     */
    private static function normalize(object|array $row): array
    {
        $row = (array) $row;
        $row['id'] = (string) ($row['instance_id'] ?? '');

        return $row;
    }

    private const ROW_COLUMNS = [
        'i.instance_id',
        'i.name',
        'i.description',
        'i.icon_url',
        'i.sort',
        'i.revision',
        'i.game_version',
        'i.loader',
        'i.loader_version',
        'i.server_address',
        'i.server_port',
        'i.required',
        'i.enabled',
        'p.url as pack_url',
        'p.sha1 as pack_sha1',
        'p.size as pack_size',
    ];
}
