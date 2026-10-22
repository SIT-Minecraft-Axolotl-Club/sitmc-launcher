<?php

namespace SITMinecraft\Launcher\Interop;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SITMinecraft\Launcher\Database\LauncherSchema;

/**
 * Persistence for the mc-interop exit.
 *
 * The legacy tables are read as-is; this repository never changes what
 * `/api/launcher/manifest` sees. Interop-only state is:
 *
 * - site identity (id/description/domain) and aggregation settings;
 * - the peer allowlist and its cached snapshots;
 * - one `published` flag per instance — publication is an explicit opt-in and
 *   defaults to off, so a site never exposes its whole instance list by
 *   accident.
 */
final class InteropRepository
{
    public const DEFAULT_SITE_ID = 'sitmc';

    private const SETTING_SITE_ID = 'interop_site_id';

    private const SETTING_SITE_DESCRIPTION = 'interop_site_description';

    private const SETTING_SITE_DOMAIN = 'interop_site_domain';

    private const SETTING_AGGREGATE = 'interop_aggregate';

    private const SETTING_PEER_TTL = 'interop_peer_ttl';

    private const SETTING_UPDATED_AT = 'interop_updated_at';

    private const SETTING_SYNC_TOKEN = 'interop_sync_token';

    private const SETTING_KEYS = [
        self::SETTING_SITE_ID,
        self::SETTING_SITE_DESCRIPTION,
        self::SETTING_SITE_DOMAIN,
        self::SETTING_AGGREGATE,
        self::SETTING_PEER_TTL,
        self::SETTING_UPDATED_AT,
    ];

    public function __construct()
    {
        LauncherSchema::install();
    }

    /**
     * @return array<string, mixed>
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

        $description = $stored->has(self::SETTING_SITE_DESCRIPTION)
            ? trim((string) $stored[self::SETTING_SITE_DESCRIPTION])
            : self::siteDescription();

        return [
            'site_id' => $text(self::SETTING_SITE_ID) ?? self::DEFAULT_SITE_ID,
            // Read-only: the site name always comes from Blessing Skin itself.
            'site_name' => self::siteName(),
            'site_description' => $description,
            'site_domain' => $text(self::SETTING_SITE_DOMAIN),
            'aggregate' => ($stored[self::SETTING_AGGREGATE] ?? '1') !== '0',
            'peer_ttl' => max(60, (int) ($stored[self::SETTING_PEER_TTL] ?? 300)),
            'updated_at' => InteropRules::timestamp($stored[self::SETTING_UPDATED_AT] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function saveSettings(array $values): void
    {
        $mapping = [
            'site_id' => self::SETTING_SITE_ID,
            'site_description' => self::SETTING_SITE_DESCRIPTION,
            'site_domain' => self::SETTING_SITE_DOMAIN,
            'aggregate' => self::SETTING_AGGREGATE,
            'peer_ttl' => self::SETTING_PEER_TTL,
        ];

        foreach ($mapping as $key => $name) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];

            if ($key === 'aggregate') {
                $value = $value ? '1' : '0';
            }

            $this->putSetting($name, $value === null ? '' : (string) $value);
        }

        $this->putSetting(self::SETTING_UPDATED_AT, Carbon::now()->toDateTimeString());
    }

    /**
     * Every instance joined with its pack history and publication flag.
     *
     * @return array<int, array<string, mixed>>
     */
    public function interopRows(): array
    {
        $instances = DB::table(LauncherSchema::INSTANCES)
            ->orderBy('sort')
            ->orderBy('name')
            ->get();

        if ($instances->isEmpty()) {
            return [];
        }

        $ids = $instances->pluck('instance_id')->all();
        $packs = DB::table(LauncherSchema::PACKS)
            ->whereIn('instance_id', $ids)
            ->orderBy('revision')
            ->get()
            ->groupBy('instance_id');
        $settings = DB::table(LauncherSchema::INTEROP_SERVERS)
            ->whereIn('instance_id', $ids)
            ->get()
            ->keyBy('instance_id');

        $rows = [];

        foreach ($instances as $instance) {
            $row = (array) $instance;
            $id = (string) ($row['instance_id'] ?? '');
            $row['id'] = $id;

            $packRows = [];

            foreach ($packs->get($id) ?? [] as $pack) {
                $packRows[] = (array) $pack;
            }

            $row['packs'] = $packRows;

            $serverSettings = $settings->get($id);
            $row['published'] = $serverSettings !== null && (bool) $serverSettings->published;

            $updatedAt = InteropRules::timestamp($row['updated_at'] ?? null);

            foreach ($packRows as $pack) {
                $updatedAt = self::later($updatedAt, InteropRules::timestamp($pack['updated_at'] ?? null));
            }

            if ($serverSettings !== null) {
                $updatedAt = self::later($updatedAt, InteropRules::timestamp($serverSettings->updated_at ?? null));
            }

            $row['updated_at'] = $updatedAt;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function instanceRow(string $instanceId): ?array
    {
        foreach ($this->interopRows() as $row) {
            if ((string) ($row['id'] ?? '') === $instanceId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Replaces the publication selection: every existing instance is either
     * published or explicitly not published.
     *
     * @param  array<int, string>  $publishedIds
     */
    public function savePublications(array $publishedIds): void
    {
        $publishedIds = array_fill_keys(array_map('strval', $publishedIds), true);
        $instances = DB::table(LauncherSchema::INSTANCES)->pluck('instance_id');

        foreach ($instances as $instanceId) {
            $instanceId = (string) $instanceId;
            $published = isset($publishedIds[$instanceId]);
            $existing = DB::table(LauncherSchema::INTEROP_SERVERS)
                ->where('instance_id', $instanceId)
                ->exists();

            if ($existing) {
                DB::table(LauncherSchema::INTEROP_SERVERS)
                    ->where('instance_id', $instanceId)
                    ->update([
                        'published' => $published,
                        'updated_at' => Carbon::now(),
                    ]);
            } else {
                DB::table(LauncherSchema::INTEROP_SERVERS)->insert([
                    'instance_id' => $instanceId,
                    'published' => $published,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }
        }
    }

    /**
     * Token used by the cron endpoint. Generated lazily and stored as a setting,
     * so the web UI can show a ready-to-paste cron command.
     */
    public function syncToken(): string
    {
        $token = trim((string) DB::table(LauncherSchema::SETTINGS)
            ->where('name', self::SETTING_SYNC_TOKEN)
            ->value('value'));

        if ($token === '') {
            $token = self::newToken();
            $this->putSetting(self::SETTING_SYNC_TOKEN, $token);
        }

        return $token;
    }

    public function rotateSyncToken(): string
    {
        $token = self::newToken();
        $this->putSetting(self::SETTING_SYNC_TOKEN, $token);

        return $token;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function peers(bool $onlyEnabled = false): array
    {
        $query = DB::table(LauncherSchema::INTEROP_PEERS)->orderBy('site_id');

        if ($onlyEnabled) {
            $query->where('enabled', true);
        }

        return $query->get()->map(static function ($row): array {
            $row = (array) $row;

            return [
                'id' => (int) $row['id'],
                'site_id' => (string) $row['site_id'],
                'name' => $row['name'] === null ? null : (string) $row['name'],
                'description' => $row['description'] === null ? null : (string) $row['description'],
                'origin' => (string) $row['origin'],
                'manifest_url' => $row['manifest_url'] === null ? null : (string) $row['manifest_url'],
                'enabled' => (bool) $row['enabled'],
                'updated_at' => InteropRules::timestamp($row['updated_at'] ?? null),
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function peer(string $siteId): ?array
    {
        foreach ($this->peers() as $peer) {
            if ($peer['site_id'] === $siteId) {
                return $peer;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function savePeer(array $values): void
    {
        $siteId = (string) $values['site_id'];
        $existing = DB::table(LauncherSchema::INTEROP_PEERS)->where('site_id', $siteId)->first();

        $payload = [
            'site_id' => $siteId,
            'name' => $values['name'] ?? null,
            'description' => $values['description'] ?? null,
            'origin' => (string) $values['origin'],
            'manifest_url' => $values['manifest_url'] ?? null,
            'enabled' => (bool) ($values['enabled'] ?? true),
            'updated_at' => Carbon::now(),
        ];

        if ($existing === null) {
            $payload['created_at'] = Carbon::now();
            DB::table(LauncherSchema::INTEROP_PEERS)->insert($payload);

            return;
        }

        if (
            (string) $existing->origin !== $payload['origin']
            || (string) ($existing->manifest_url ?? '') !== (string) ($payload['manifest_url'] ?? '')
        ) {
            $this->deleteSnapshot($siteId);
        }

        DB::table(LauncherSchema::INTEROP_PEERS)->where('site_id', $siteId)->update($payload);
    }

    public function deletePeer(string $siteId): void
    {
        DB::table(LauncherSchema::INTEROP_PEERS)->where('site_id', $siteId)->delete();
        $this->deleteSnapshot($siteId);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function snapshots(): array
    {
        $snapshots = [];

        foreach (DB::table(LauncherSchema::INTEROP_SNAPSHOTS)->get() as $row) {
            $row = (array) $row;
            $snapshots[(string) $row['site_id']] = [
                'site_id' => (string) $row['site_id'],
                'origin' => (string) $row['origin'],
                'etag' => $row['etag'] === null ? null : (string) $row['etag'],
                'document' => $row['document'] === null ? null : (string) $row['document'],
                'fetched_at' => InteropRules::timestamp($row['fetched_at'] ?? null),
                'stale' => (bool) $row['stale'],
                'last_error' => $row['last_error'] === null ? null : (string) $row['last_error'],
            ];
        }

        return $snapshots;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function snapshot(string $siteId): ?array
    {
        return $this->snapshots()[$siteId] ?? null;
    }

    public function storeSnapshot(
        string $siteId,
        string $origin,
        ?string $etag,
        string $document,
        ?int $fetchedAt = null
    ): void {
        $payload = [
            'origin' => $origin,
            'etag' => $etag,
            'manifest_version' => null,
            'document' => $document,
            'fetched_at' => $fetchedAt === null
                ? Carbon::now()
                : date('Y-m-d H:i:s', $fetchedAt),
            'stale' => false,
            'last_error' => null,
            'updated_at' => Carbon::now(),
        ];

        $existing = DB::table(LauncherSchema::INTEROP_SNAPSHOTS)->where('site_id', $siteId)->exists();

        if ($existing) {
            DB::table(LauncherSchema::INTEROP_SNAPSHOTS)->where('site_id', $siteId)->update($payload);

            return;
        }

        $payload['site_id'] = $siteId;
        $payload['created_at'] = Carbon::now();
        DB::table(LauncherSchema::INTEROP_SNAPSHOTS)->insert($payload);
    }

    public function markNotModified(string $siteId, ?string $etag = null): void
    {
        $payload = [
            'fetched_at' => Carbon::now(),
            'stale' => false,
            'last_error' => null,
            'updated_at' => Carbon::now(),
        ];

        if ($etag !== null && trim($etag) !== '') {
            $payload['etag'] = $etag;
        }

        DB::table(LauncherSchema::INTEROP_SNAPSHOTS)->where('site_id', $siteId)->update($payload);
    }

    public function markFailure(string $siteId, string $error, bool $stale = true): void
    {
        if (! DB::table(LauncherSchema::INTEROP_SNAPSHOTS)->where('site_id', $siteId)->exists()) {
            return;
        }

        DB::table(LauncherSchema::INTEROP_SNAPSHOTS)->where('site_id', $siteId)->update([
            'stale' => $stale,
            'last_error' => mb_substr($error, 0, 255),
            'updated_at' => Carbon::now(),
        ]);
    }

    public function deleteSnapshot(string $siteId): void
    {
        DB::table(LauncherSchema::INTEROP_SNAPSHOTS)->where('site_id', $siteId)->delete();
    }

    /**
     * Latest change of anything that feeds the unified exit.
     */
    public function interopUpdatedAt(): ?int
    {
        $latest = InteropRules::timestamp(
            DB::table(LauncherSchema::SETTINGS)
                ->where('name', self::SETTING_UPDATED_AT)
                ->value('value')
        );

        foreach ([LauncherSchema::INTEROP_PEERS, LauncherSchema::INTEROP_SERVERS] as $table) {
            $latest = self::later($latest, InteropRules::timestamp(
                DB::table($table)->max('updated_at')
            ));
        }

        return $latest;
    }

    private function putSetting(string $name, string $value): void
    {
        DB::table(LauncherSchema::SETTINGS)->updateOrInsert(
            ['name' => $name],
            ['value' => $value]
        );
    }

    private static function siteName(): string
    {
        if (function_exists('option')) {
            $value = option('site_name', '');
            $name = is_scalar($value) ? trim((string) $value) : '';

            if ($name !== '') {
                return $name;
            }
        }

        $name = function_exists('config') ? trim((string) config('app.name', '')) : '';

        return $name === '' ? 'SIT Minecraft' : $name;
    }

    private static function newToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    private static function siteDescription(): string
    {
        if (function_exists('option')) {
            $value = option('site_description', '');
            $description = is_scalar($value) ? trim((string) $value) : '';

            if ($description !== '') {
                return $description;
            }
        }

        return '';
    }

    private static function later(?int $current, ?int $candidate): ?int
    {
        if ($candidate === null) {
            return $current;
        }

        if ($current === null) {
            return $candidate;
        }

        return max($current, $candidate);
    }
}
