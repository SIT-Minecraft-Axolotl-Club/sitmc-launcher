<?php

namespace SITMinecraft\Launcher\Interop;

/**
 * Assembles the unified exit for a request.
 *
 * The public well-known endpoint and the admin preview both go through this
 * service, so the document the administrator sees is the one a launcher
 * receives. The legacy `/api/launcher/manifest` is a different, untouched
 * document.
 */
final class SiteDocumentService
{
    public function __construct(
        private InteropRepository $repository,
        private PeerAggregator $aggregator,
    ) {}

    /**
     * The canonical site domain: the configured host wins, otherwise the host
     * of the current request. Only the host is kept — never a scheme or path.
     */
    public function domain(?string $requestRoot = null): string
    {
        $settings = $this->repository->settings();
        $configured = InteropRules::host($settings['site_domain'] ?? null);

        if ($configured !== null) {
            return $configured;
        }

        $requestHost = parse_url((string) $requestRoot, PHP_URL_HOST);
        $requestDomain = InteropRules::host($requestHost);

        return $requestDomain ?? 'localhost';
    }

    /**
     * @return array{document: array<string, mixed>, diagnostics: array<int, array<string, mixed>>, domain: string, settings: array<string, mixed>}
     */
    public function document(?string $requestRoot = null): array
    {
        $settings = $this->repository->settings();
        $domain = $this->domain($requestRoot);
        $peer = $this->aggregator->peerDocuments();

        $builder = new SiteDocumentBuilder($domain);

        $document = $builder->build($this->repository->interopRows(), [
            'site' => [
                'id' => $settings['site_id'],
                'name' => $settings['site_name'],
                'description' => $settings['site_description'],
                'domain' => $domain,
            ],
            'peers' => $this->repository->peers(true),
            'peerDocuments' => $peer['documents'],
            'settingsUpdatedAt' => $this->repository->interopUpdatedAt(),
        ]);

        return [
            'document' => $document,
            'diagnostics' => array_merge($builder->diagnostics(), $peer['diagnostics']),
            'domain' => $domain,
            'settings' => $settings,
        ];
    }

    /**
     * Friend-only catalog for launchers, shaped like the legacy
     * `/api/launcher/manifest` document but grouped by source site:
     * `schema`, `generated_at`, `sites[]`, and each site carries `instances[]`.
     *
     * @return array{document: array<string, mixed>, diagnostics: array<int, array<string, mixed>>, domain: string, settings: array<string, mixed>}
     */
    public function friends(?string $requestRoot = null): array
    {
        $settings = $this->repository->settings();
        $domain = $this->domain($requestRoot);
        $peer = $this->aggregator->peerDocuments();
        $peerRows = $this->repository->peers(true);

        $builder = new SiteDocumentBuilder($domain);

        $interop = $builder->build([], [
            'site' => [
                'id' => $settings['site_id'],
                'name' => $settings['site_name'],
                'description' => $settings['site_description'],
                'domain' => $domain,
            ],
            'peers' => $peerRows,
            'peerDocuments' => $peer['documents'],
            'settingsUpdatedAt' => $this->repository->interopUpdatedAt(),
        ]);

        $bySite = [];

        foreach ($peerRows as $row) {
            $bySite[$row['site_id']] = $row;
        }

        $sites = [];

        foreach ($interop['packs'] as $entry) {
            $source = is_array($entry['source'] ?? null) ? $entry['source'] : [];
            $siteId = (string) ($source['siteId'] ?? '');
            $row = $bySite[$siteId] ?? null;
            $sourceDomain = (string) ($source['domain'] ?? '');
            $manifestUrl = $row['manifest_url'] ?? null;

            if (! is_string($manifestUrl) || trim($manifestUrl) === '') {
                $manifestUrl = 'https://'.$sourceDomain.InteropRules::WELL_KNOWN_PATH;
            }

            $name = $row['name'] ?? null;

            if (! is_string($name) || trim($name) === '') {
                $name = $siteId;
            }

            $artifact = is_array($entry['artifact'] ?? null) ? $entry['artifact'] : null;
            $loader = $entry['loader'] ?? null;

            if (! isset($sites[$siteId])) {
                $sites[$siteId] = [
                    'site_id' => $siteId,
                    'name' => $name,
                    'description' => $row['description'] ?? null,
                    'domain' => $sourceDomain,
                    'manifest_url' => $manifestUrl,
                    'stale' => false,
                    'instances' => [],
                ];
            }

            $sites[$siteId]['instances'][] = [
                'id' => (string) ($entry['id'] ?? ''),
                'name' => (string) ($entry['name'] ?? $entry['id'] ?? ''),
                'description' => $entry['summary'] ?? null,
                'icon_url' => null,
                'sort' => 0,
                'revision' => $artifact === null ? null : (int) ($entry['revision'] ?? 0),
                'minecraft' => [
                    'game_version' => (string) ($entry['gameVersion'] ?? ''),
                    'loader' => $loader === 'vanilla' ? null : $loader,
                    'loader_version' => $entry['loaderVersion'] ?? null,
                ],
                'pack' => $artifact === null ? null : [
                    'kind' => (string) ($artifact['format'] ?? 'mrpack'),
                    'url' => (string) ($artifact['url'] ?? ''),
                    'sha1' => $artifact['sha1'] ?? null,
                    'size' => isset($artifact['size']) ? (int) $artifact['size'] : null,
                ],
                'server' => [
                    'address' => (string) ($entry['server']['address'] ?? ''),
                    'port' => isset($entry['server']['port']) ? (int) $entry['server']['port'] : null,
                ],
                'required' => false,
            ];

            $sites[$siteId]['stale'] = $sites[$siteId]['stale'] || (bool) ($source['stale'] ?? false);
        }

        $sites = array_values($sites);

        foreach ($sites as &$site) {
            usort($site['instances'], static function (array $left, array $right): int {
                return [$left['id'], $left['name']] <=> [$right['id'], $right['name']];
            });

            // `sort` mirrors the legacy manifest field, but is generated here
            // (1..n per site) instead of trusting each peer's own ordering.
            foreach ($site['instances'] as $index => &$instance) {
                $instance['sort'] = $index + 1;
            }

            unset($instance);
        }

        unset($site);

        usort($sites, static function (array $left, array $right): int {
            return [$left['name'], $left['site_id']] <=> [$right['name'], $right['site_id']];
        });

        return [
            'document' => [
                'schema' => 1,
                'generated_at' => $interop['updatedAt'],
                'sites' => $sites,
            ],
            'diagnostics' => array_merge($builder->diagnostics(), $peer['diagnostics']),
            'domain' => $domain,
            'settings' => $settings,
        ];
    }

    public function scheduleRefresh(): void
    {
        $this->aggregator->scheduleRefreshIfStale();
    }
}
