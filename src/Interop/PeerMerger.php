<?php

namespace SITMinecraft\Launcher\Interop;

/**
 * Merges the flat `packs` arrays published by peer sites into this site's own
 * array (protocol §7).
 *
 * Merging is pure: no I/O. Foreign entries carry
 * `source: {siteId, domain, stale?}`, are validated with the same field rules as
 * local entries, and lose on id collision so a server binding can never point at
 * an ambiguous pack.
 */
final class PeerMerger
{
    /** @var array<int, array<string, mixed>> */
    private array $diagnostics = [];

    /**
     * @param  array<int, array{siteId?: string, domain?: string, origin?: string, stale?: bool, document?: mixed}>  $documents
     * @param  array<int, string>  $ownIds
     * @return array{packs: array<int, array<string, mixed>>, updatedAt: ?int, diagnostics: array<int, array<string, mixed>>}
     */
    public function merge(array $documents, array $ownIds = []): array
    {
        $this->diagnostics = [];

        $packs = [];
        $seen = array_fill_keys($ownIds, true);
        $updatedAt = null;

        usort($documents, static function (array $left, array $right): int {
            return [(string) ($left['siteId'] ?? ''), (string) ($left['domain'] ?? '')]
                <=> [(string) ($right['siteId'] ?? ''), (string) ($right['domain'] ?? '')];
        });

        foreach ($documents as $document) {
            $payload = $document['document'] ?? null;

            if (! is_array($payload)) {
                $this->diagnose('peer-document-invalid', $document['siteId'] ?? null);
                continue;
            }

            $siteId = InteropRules::id($payload['site']['id'] ?? null)
                ?? InteropRules::id($document['siteId'] ?? null);
            $domain = InteropRules::host($payload['site']['domain'] ?? null)
                ?? InteropRules::host($document['domain'] ?? null)
                ?? InteropRules::host(parse_url((string) ($document['origin'] ?? ''), PHP_URL_HOST));

            if ($siteId === null || $domain === null) {
                $this->diagnose('peer-document-invalid', $siteId);
                continue;
            }

            $updatedAt = self::later($updatedAt, InteropRules::timestamp($payload['updatedAt'] ?? null));

            $source = ['siteId' => $siteId, 'domain' => $domain];

            if ((bool) ($document['stale'] ?? false)) {
                $source['stale'] = true;
            }

            $items = $payload['packs'] ?? null;

            if (! is_array($items)) {
                $this->diagnose('peer-packs-missing', $siteId);
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    $this->diagnose('peer-entry-invalid', $siteId);
                    continue;
                }

                // Only the peer's own entries are ingested. Entries that
                // already carry `source` are the peer's view of another site;
                // re-ingesting them would double-prefix ids and echo entries
                // back to their origin.
                if (isset($item['source'])) {
                    continue;
                }

                $entry = $this->entry($item, $siteId);

                if ($entry === null) {
                    continue;
                }

                // Namespace every foreign id with its source site. This is
                // what lets two sites both publish `survival` without losing
                // one of them; the launcher sees `ysumc:survival`.
                $entry['id'] = $siteId.':'.$entry['id'];

                if (isset($seen[$entry['id']])) {
                    $this->diagnose('peer-entry-id-collision', $siteId, $entry['id']);
                    continue;
                }

                $entry['source'] = $source;
                $packs[] = $entry;
                $seen[$entry['id']] = true;
            }
        }

        return [
            'packs' => $packs,
            'updatedAt' => $updatedAt,
            'diagnostics' => $this->diagnostics,
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
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function entry(array $item, string $siteId): ?array
    {
        $id = InteropRules::id($item['id'] ?? null);

        if ($id === null) {
            $this->diagnose('peer-entry-id-invalid', $siteId);
            return null;
        }

        $server = is_array($item['server'] ?? null) ? $item['server'] : null;
        $address = InteropRules::text($server['address'] ?? null, 255);

        if ($address === null) {
            $this->diagnose('peer-entry-server-missing', $siteId, $id);
            return null;
        }

        $gameVersion = InteropRules::text($item['gameVersion'] ?? null, 64);

        if ($gameVersion === null) {
            $this->diagnose('peer-entry-game-version-missing', $siteId, $id);
            return null;
        }

        $loader = InteropRules::loader($item['loader'] ?? null);

        if ($loader === null) {
            $this->diagnose('peer-entry-loader-invalid', $siteId, $id);
            return null;
        }

        $entry = [
            'id' => $id,
            'name' => InteropRules::text($item['name'] ?? null, 128) ?? $id,
        ];

        $summary = InteropRules::text($item['summary'] ?? null, 2000);

        if ($summary !== null) {
            $entry['summary'] = $summary;
        }

        $artifact = $item['artifact'] ?? null;

        if ($artifact !== null) {
            if (! is_array($artifact)) {
                $this->diagnose('peer-entry-artifact-invalid', $siteId, $id);
                return null;
            }

            $revision = InteropRules::revision($item['revision'] ?? null);

            if ($revision === null) {
                $this->diagnose('peer-entry-revision-invalid', $siteId, $id);
                return null;
            }

            $artifact = $this->artifact($artifact, $siteId, $id);

            if ($artifact === null) {
                return null;
            }

            $entry['revision'] = $revision;
            $entry['gameVersion'] = $gameVersion;
            $entry['loader'] = $loader;

            if ($loader !== 'vanilla') {
                $loaderVersion = InteropRules::text($item['loaderVersion'] ?? null, 64);

                if ($loaderVersion !== null) {
                    $entry['loaderVersion'] = $loaderVersion;
                }
            }

            $entry['artifact'] = $artifact;
        } else {
            // `revision` is only valid together with an artifact.
            if (array_key_exists('revision', $item)) {
                $this->diagnose('peer-entry-revision-without-artifact', $siteId, $id);
                return null;
            }

            $entry['gameVersion'] = $gameVersion;
            $entry['loader'] = $loader;

            if ($loader !== 'vanilla') {
                $loaderVersion = InteropRules::text($item['loaderVersion'] ?? null, 64);

                if ($loaderVersion !== null) {
                    $entry['loaderVersion'] = $loaderVersion;
                }
            }
        }

        $serverEntry = ['address' => $address];
        $port = InteropRules::port($server['port'] ?? null);

        if ($port !== null) {
            $serverEntry['port'] = $port;
        }

        $entry['server'] = $serverEntry;

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>|null
     */
    private function artifact(array $artifact, string $siteId, string $id): ?array
    {
        $format = InteropRules::format($artifact['format'] ?? null);

        if ($format === null) {
            $this->diagnose('peer-entry-artifact-invalid', $siteId, $id);
            return null;
        }

        $url = InteropRules::publicHttpsUrl($artifact['url'] ?? null);
        $size = InteropRules::size($artifact['size'] ?? null);
        $sha1 = InteropRules::sha1($artifact['sha1'] ?? null);
        $sha512 = InteropRules::sha512($artifact['sha512'] ?? null);

        if ($url === null || $size === null || ($sha1 === null && $sha512 === null)) {
            $this->diagnose('peer-entry-artifact-invalid', $siteId, $id);
            return null;
        }

        $out = [
            'format' => $format,
            'url' => $url,
            'size' => $size,
            'sha1' => $sha1,
            'sha512' => $sha512,
        ];

        $distribution = is_array($artifact['distribution'] ?? null)
            ? $artifact['distribution']
            : null;

        if ($distribution !== null) {
            $system = InteropRules::distributionSystem($distribution['system'] ?? null);

            if ($system !== null) {
                $dist = ['system' => $system];

                foreach (['packId', 'versionId'] as $key) {
                    $value = InteropRules::text($distribution[$key] ?? null, 128);

                    if ($value !== null) {
                        $dist[$key] = $value;
                    }
                }

                $out['distribution'] = $dist;
            }
        }

        return $out;
    }

    private function diagnose(string $code, ?string $siteId = null, ?string $detail = null): void
    {
        $entry = ['code' => $code];

        if ($siteId !== null) {
            $entry['siteId'] = $siteId;
        }

        if ($detail !== null) {
            $entry['detail'] = $detail;
        }

        $this->diagnostics[] = $entry;
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
