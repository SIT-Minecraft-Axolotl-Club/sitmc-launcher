<?php

namespace SITMinecraft\Launcher\Manifest;

/**
 * Turns stored instance rows into the launcher manifest document.
 *
 * The document is the contract described in the launcher repository's
 * `docs/launcher-protocol.md`. Building it is deliberately framework free: the
 * rows are plain arrays, so the output can be asserted in a unit test without a
 * database or a booted application.
 */
final class ManifestBuilder
{
    /**
     * @param  string  $baseUrl  absolute site URL without a trailing slash, used to
     *                           build pack download links for rows without one
     */
    public function __construct(private string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function build(
        iterable $rows,
        ?string $minVersion = null,
        ?string $latestVersion = null,
        bool $forceUpdate = false,
        ?int $publishedAt = null
    ): array {
        $instances = [];

        foreach ($rows as $row) {
            if (! (bool) ($row['enabled'] ?? true)) {
                continue;
            }

            $packUrl = $this->packUrlFor($row);

            if (ManifestRules::publishProblem($row, $packUrl) !== null) {
                continue;
            }

            $instances[] = $this->instance($row, $packUrl);
        }

        usort($instances, static function (array $left, array $right): int {
            return [$left['sort'], $left['name']] <=> [$right['sort'], $right['name']];
        });

        return [
            'schema' => ManifestRules::SCHEMA,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z', $publishedAt ?? time()),
            'launcher' => [
                'min_version' => self::textOrNull($minVersion),
                'latest_version' => self::textOrNull($latestVersion),
                'force_update' => $forceUpdate,
            ],
            'instances' => $instances,
        ];
    }

    /**
     * Stable identifier of a document, so a manifest that did not change can be
     * answered with 304 instead of being downloaded again.
     *
     * @param  array<string, mixed>  $document
     */
    public function etag(array $document): string
    {
        return '"'.sha1($this->encode($document)).'"';
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function encode(array $document): string
    {
        return (string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Public URL of a row's pack: the external URL when the administrator gave
     * one, otherwise the plugin's own download route.
     *
     * @param  array<string, mixed>  $row
     */
    public function packUrlFor(array $row): string
    {
        $external = trim((string) ($row['pack_url'] ?? ''));

        if ($external !== '') {
            return $external;
        }

        return sprintf(
            '%s/api/launcher/packs/%s/%d',
            $this->baseUrl,
            rawurlencode((string) ($row['id'] ?? '')),
            (int) ($row['revision'] ?? 0)
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function instance(array $row, string $packUrl): array
    {
        $address = trim((string) ($row['server_address'] ?? ''));
        $port = (int) ($row['server_port'] ?? 0);

        return [
            'id' => (string) $row['id'],
            'name' => (string) ($row['name'] ?? $row['id']),
            'description' => self::textOrNull($row['description'] ?? null),
            'icon_url' => self::textOrNull($row['icon_url'] ?? null),
            'sort' => (int) ($row['sort'] ?? 0),
            'revision' => (int) ($row['revision'] ?? 0),
            'minecraft' => [
                'game_version' => (string) $row['game_version'],
                'loader' => self::textOrNull($row['loader'] ?? null),
                'loader_version' => self::textOrNull($row['loader_version'] ?? null),
            ],
            'pack' => [
                'kind' => ManifestRules::PACK_KIND,
                'url' => $packUrl,
                'sha1' => ManifestRules::normalizeSha1($row['pack_sha1'] ?? null),
                'size' => self::sizeOrNull($row['pack_size'] ?? null),
            ],
            'server' => $address === '' ? null : [
                'address' => $address,
                'port' => $port > 0 ? $port : null,
            ],
            'required' => (bool) ($row['required'] ?? true),
        ];
    }

    private static function textOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function sizeOrNull(mixed $value): ?int
    {
        $size = (int) $value;

        return $size > 0 ? $size : null;
    }
}
