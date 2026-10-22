<?php

namespace SITMinecraft\Launcher\Interop;

/**
 * Serialises the selected instances into the single mc-interop v1 SiteDocument.
 *
 * v1 is one flat `packs` array: every entry is an instance (pack + server +
 * version) and the pack part may be omitted for a vanilla server. The legacy
 * `/api/launcher/manifest` keeps its schema:1 shape untouched; this serializer
 * only feeds the new well-known exit.
 *
 * The builder is framework free (plain arrays in, plain arrays out) so the
 * projection can be asserted without a booted application. Data access lives in
 * {@see InteropRepository}; peer merging in {@see PeerMerger}.
 */
final class SiteDocumentBuilder
{
    private string $domain;

    private PeerMerger $merger;

    /** @var array<int, array<string, mixed>> */
    private array $diagnostics = [];

    public function __construct(string $domain, ?PeerMerger $merger = null)
    {
        $this->domain = InteropRules::host($domain) ?? 'localhost';
        $this->merger = $merger ?? new PeerMerger();
    }

    /**
     * @param  iterable<array<string, mixed>>  $instances  rows with `packs`, `published` and `updated_at`
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(iterable $instances, array $options = []): array
    {
        $this->diagnostics = [];

        $instances = is_array($instances) ? $instances : iterator_to_array($instances);
        $now = (int) ($options['now'] ?? time());
        $settingsAt = InteropRules::timestamp($options['settingsUpdatedAt'] ?? null);

        $own = $this->ownEntries($instances);

        $peerDocuments = is_array($options['peerDocuments'] ?? null) ? $options['peerDocuments'] : [];
        $merged = $this->merger->merge($peerDocuments, array_column($own['entries'], 'id'));

        $packs = $this->sortEntries(array_merge($own['entries'], $merged['packs']));
        $updatedAt = self::latest([$own['updatedAt'], $settingsAt, $merged['updatedAt']]) ?? $now;

        $site = $this->site($options['site'] ?? []);
        $peers = $this->peers($options['peers'] ?? [], $site['id']);

        $this->diagnostics = array_merge($this->diagnostics, $merged['diagnostics']);

        return [
            'site' => $site,
            'packs' => $packs,
            'peers' => $peers,
            'updatedAt' => InteropRules::isoUtc($updatedAt),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @param  array<int, array<string, mixed>>  $instances
     * @return array{entries: array<int, array<string, mixed>>, updatedAt: ?int}
     */
    private function ownEntries(array $instances): array
    {
        $entries = [];
        $updatedAt = null;

        foreach ($instances as $instance) {
            if (! is_array($instance)) {
                continue;
            }

            // Publication is an explicit opt-in: never publish an instance the
            // administrator did not select, even if it exists in the database.
            if (! (bool) ($instance['published'] ?? false)) {
                continue;
            }

            $id = InteropRules::id($instance['id'] ?? ($instance['instance_id'] ?? null));

            if ($id === null) {
                $this->diagnose('instance-id-invalid');
                continue;
            }

            $updatedAt = self::later($updatedAt, InteropRules::timestamp($instance['updated_at'] ?? null));

            $server = $this->server($instance);

            if ($server === null) {
                $this->diagnose('server-missing', $id);
                continue;
            }

            $gameVersion = InteropRules::text($instance['game_version'] ?? null, 64);

            if ($gameVersion === null) {
                $this->diagnose('game-version-missing', $id);
                continue;
            }

            $loader = self::loaderOrDefault($instance['loader'] ?? null);

            if ($loader === null) {
                $this->diagnose('loader-invalid', $id, [
                    'loader' => (string) ($instance['loader'] ?? ''),
                ]);
                continue;
            }

            $loaderVersion = $loader === 'vanilla'
                ? null
                : InteropRules::text($instance['loader_version'] ?? null, 64);

            $entry = [
                'id' => $id,
                'name' => InteropRules::text($instance['name'] ?? null, 128) ?? $id,
            ];

            $summary = InteropRules::text($instance['description'] ?? null, 2000);

            if ($summary !== null) {
                $entry['summary'] = $summary;
            }

            $rows = is_array($instance['packs'] ?? null) ? $instance['packs'] : [];

            foreach ($rows as $row) {
                if (is_array($row)) {
                    $updatedAt = self::later($updatedAt, InteropRules::timestamp($row['updated_at'] ?? null));
                }
            }

            if ($rows !== []) {
                $revision = InteropRules::revision($instance['revision'] ?? null);

                if ($revision === null) {
                    $this->diagnose('revision-invalid', $id);
                    continue;
                }

                $pack = $this->currentPack($rows, $revision);

                if ($pack === null) {
                    $this->diagnose('pack-revision-missing', $id, ['revision' => $revision]);
                    continue;
                }

                $artifact = $this->artifact($id, $revision, $pack);

                if ($artifact === null) {
                    // A published pack instance with unusable artifact metadata
                    // is dropped instead of silently degrading to vanilla.
                    continue;
                }

                $entry['revision'] = $revision;
                $entry['gameVersion'] = $gameVersion;
                $entry['loader'] = $loader;

                if ($loader !== 'vanilla' && $loaderVersion !== null) {
                    $entry['loaderVersion'] = $loaderVersion;
                }

                $entry['artifact'] = $artifact;
            } else {
                // No pack: a vanilla server. Version is gameVersion + loader.
                $entry['gameVersion'] = $gameVersion;
                $entry['loader'] = $loader;

                if ($loader !== 'vanilla' && $loaderVersion !== null) {
                    $entry['loaderVersion'] = $loaderVersion;
                }
            }

            $entry['server'] = $server;
            $entries[] = $entry;
        }

        return ['entries' => $entries, 'updatedAt' => $updatedAt];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function currentPack(array $rows, int $revision): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && (int) ($row['revision'] ?? -1) === $revision) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function artifact(string $id, int $revision, array $row): ?array
    {
        $external = trim((string) ($row['url'] ?? ''));

        $url = $external !== ''
            ? InteropRules::publicHttpsUrl($external)
            : InteropRules::httpsUrl(sprintf(
                'https://%s/api/launcher/packs/%s/%d',
                $this->domain,
                rawurlencode($id),
                $revision
            ));

        if ($url === null) {
            $this->diagnose('artifact-url-invalid', $id, ['revision' => $revision]);
            return null;
        }

        $size = InteropRules::size($row['size'] ?? null);

        if ($size === null) {
            $this->diagnose('artifact-size-missing', $id, ['revision' => $revision]);
            return null;
        }

        $sha1 = InteropRules::sha1($row['sha1'] ?? null);
        $sha512 = InteropRules::sha512($row['sha512'] ?? null);

        if ($sha1 === null && $sha512 === null) {
            $this->diagnose('artifact-hash-missing', $id, ['revision' => $revision]);
            return null;
        }

        return [
            'format' => 'mrpack',
            'url' => $url,
            'size' => $size,
            'sha1' => $sha1,
            'sha512' => $sha512,
        ];
    }

    /**
     * @param  array<string, mixed>  $instance
     * @return array{address: string, port?: int}|null
     */
    private function server(array $instance): ?array
    {
        $address = InteropRules::text($instance['server_address'] ?? null, 255);

        if ($address === null) {
            return null;
        }

        $server = ['address' => $address];
        $port = InteropRules::port($instance['server_port'] ?? null);

        if ($port !== null) {
            $server['port'] = $port;
        }

        return $server;
    }

    /**
     * @param  array<string, mixed>  $site
     * @return array<string, string>
     */
    private function site(array $site): array
    {
        $id = InteropRules::id($site['id'] ?? null);

        if ($id === null) {
            $this->diagnose('site-id-invalid');
            $id = 'sitmc';
        }

        $requestedDomain = $site['domain'] ?? null;
        $domain = InteropRules::host($requestedDomain) ?? $this->domain;

        if (
            $requestedDomain !== null
            && trim((string) $requestedDomain) !== ''
            && InteropRules::host($requestedDomain) === null
        ) {
            $this->diagnose('site-domain-invalid');
        }

        $description = InteropRules::text($site['description'] ?? null, 2000);

        return [
            'id' => $id,
            'name' => InteropRules::text($site['name'] ?? null, 128) ?? $id,
            'description' => $description ?? '',
            'domain' => $domain,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function peers(mixed $value, string $siteId): array
    {
        if (! is_array($value)) {
            return [];
        }

        $peers = [];
        $seen = [];

        foreach ($value as $peer) {
            if (! is_array($peer) || ! (bool) ($peer['enabled'] ?? true)) {
                continue;
            }

            // Placeholder rows are kept private until the first successful pull
            // resolves the peer's real site id.
            if (str_starts_with((string) ($peer['site_id'] ?? ''), 'pending-')) {
                continue;
            }

            $peerId = InteropRules::id($peer['site_id'] ?? ($peer['siteId'] ?? null));
            $domain = InteropRules::host($peer['domain'] ?? null)
                ?? InteropRules::host(parse_url((string) ($peer['origin'] ?? ''), PHP_URL_HOST));

            if ($peerId === null || $domain === null) {
                $this->diagnose('peer-config-invalid', $peerId);
                continue;
            }

            if ($peerId === $siteId || isset($seen[$peerId])) {
                $this->diagnose('peer-self-or-duplicate', $peerId);
                continue;
            }

            $manifestUrl = InteropRules::publicHttpsUrl($peer['manifest_url'] ?? null)
                ?? InteropRules::publicHttpsUrl('https://'.$domain.InteropRules::WELL_KNOWN_PATH);

            if ($manifestUrl === null) {
                $this->diagnose('peer-manifest-invalid', $peerId);
                continue;
            }

            $entry = ['siteId' => $peerId];

            $name = InteropRules::text($peer['name'] ?? null, 128);

            if ($name !== null) {
                $entry['name'] = $name;
            }

            $description = InteropRules::text($peer['description'] ?? null, 2000);

            if ($description !== null) {
                $entry['description'] = $description;
            }

            $entry['domain'] = $domain;
            $entry['manifestUrl'] = $manifestUrl;

            $seen[$peerId] = true;
            $peers[] = $entry;
        }

        usort($peers, static fn (array $left, array $right): int => $left['siteId'] <=> $right['siteId']);

        return $peers;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function sortEntries(array $entries): array
    {
        usort($entries, static function (array $left, array $right): int {
            return [
                isset($left['source']) ? 1 : 0,
                (string) $left['id'],
                (string) ($left['source']['domain'] ?? ''),
            ] <=> [
                isset($right['source']) ? 1 : 0,
                (string) $right['id'],
                (string) ($right['source']['domain'] ?? ''),
            ];
        });

        return $entries;
    }

    private function diagnose(string $code, ?string $instanceId = null, array $detail = []): void
    {
        $entry = ['code' => $code];

        if ($instanceId !== null) {
            $entry['instanceId'] = $instanceId;
        }

        if ($detail !== []) {
            $entry['detail'] = $detail;
        }

        $this->diagnostics[] = $entry;
    }

    /**
     * @param  array<int, ?int>  $values
     */
    private static function latest(array $values): ?int
    {
        $latest = null;

        foreach ($values as $value) {
            $latest = self::later($latest, $value);
        }

        return $latest;
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

    private static function loaderOrDefault(mixed $value): ?string
    {
        $raw = trim((string) (is_scalar($value) ? $value : ''));

        return $raw === '' ? 'vanilla' : InteropRules::loader($raw);
    }
}
