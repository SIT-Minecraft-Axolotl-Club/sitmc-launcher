<?php

namespace SITMinecraft\Launcher\Interop;

/**
 * Resolves a friend site from a full manifest URL.
 *
 * The canonical form is the site's own `mc-interop-v1.json` address, which may
 * be nested behind a reverse proxy, e.g.
 * `https://hall.example.cn/api/plugins/mc-interop/.well-known/mc-interop-v1.json`.
 * A bare root domain is still accepted for compatibility and expands to the
 * canonical `https://{domain}/.well-known/mc-interop-v1.json`.
 */
final class PeerDiscovery
{
    public function __construct(private PeerFetcher $fetcher) {}

    /**
     * Normalize user input to a public HTTPS manifest URL. Returns null for
     * empty, non-HTTPS or private/loopback targets.
     */
    public function normalize(string $input): ?string
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        if (! preg_match('~^https?://~i', $input)) {
            if (InteropRules::host($input) !== null) {
                $input = 'https://'.$input.InteropRules::WELL_KNOWN_PATH;
            } else {
                $input = 'https://'.ltrim($input, '/');
            }
        }

        $path = parse_url($input, PHP_URL_PATH);

        if ($path === null || $path === '' || $path === '/') {
            $input = rtrim($input, '/').InteropRules::WELL_KNOWN_PATH;
        }

        return InteropRules::publicHttpsUrl($input);
    }

    /**
     * Deterministic placeholder id used while a peer is offline. It is replaced
     * by the real site id as soon as the first successful pull happens.
     */
    public function pendingId(string $manifestUrl): string
    {
        $normalized = $this->normalize($manifestUrl) ?? trim($manifestUrl);

        return 'pending-'.substr(hash('sha256', $normalized), 0, 16);
    }

    /**
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   site_id?: string,
     *   name?: string,
     *   description?: ?string,
     *   domain?: string,
     *   origin?: string,
     *   manifest_url?: string,
     *   document?: array<string, mixed>,
     *   etag?: ?string
     * }
     */
    public function discover(string $input): array
    {
        $manifestUrl = $this->normalize($input);

        if ($manifestUrl === null) {
            return ['ok' => false, 'error' => 'peer-manifest-url-invalid'];
        }

        $parts = parse_url($manifestUrl);
        $host = InteropRules::host($parts['host'] ?? null);

        if ($host === null) {
            return ['ok' => false, 'error' => 'peer-manifest-url-invalid'];
        }

        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $origin = 'https://'.$host.$port;
        $base = [
            'ok' => false,
            'manifest_url' => $manifestUrl,
            'domain' => $host,
            'origin' => $origin,
        ];

        $fetched = $this->fetcher->get($manifestUrl);

        if ($fetched['error'] !== null) {
            $error = str_starts_with($fetched['error'], 'peer-http-')
                ? 'peer-http-error'
                : $fetched['error'];

            return $base + ['error' => $error];
        }

        if ($fetched['status'] !== 200 || $fetched['body'] === null) {
            return $base + ['error' => 'peer-http-error'];
        }

        $document = json_decode($fetched['body'], true);

        if (! is_array($document)) {
            return $base + ['error' => 'peer-json-invalid'];
        }

        return $this->fromDocument($document, $host, $fetched['etag'], $origin, $manifestUrl);
    }

    /**
     * Pure document validation, separated from the HTTP fetch so it can be
     * exercised without a network peer.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function fromDocument(
        array $document,
        string $host,
        ?string $etag = null,
        ?string $origin = null,
        ?string $manifestUrl = null
    ): array {
        $origin ??= 'https://'.$host;
        $manifestUrl ??= $origin.InteropRules::WELL_KNOWN_PATH;
        $site = $document['site'] ?? null;

        if (! is_array($site) || ! is_array($document['packs'] ?? null)) {
            return ['ok' => false, 'error' => 'peer-shape-invalid'];
        }

        $siteId = InteropRules::id($site['id'] ?? null);

        if ($siteId === null) {
            return ['ok' => false, 'error' => 'peer-site-id-invalid'];
        }

        $advertisedDomain = InteropRules::host($site['domain'] ?? null);

        if ($advertisedDomain !== null && $advertisedDomain !== $host) {
            return ['ok' => false, 'error' => 'peer-domain-mismatch'];
        }

        return [
            'ok' => true,
            'site_id' => $siteId,
            'name' => InteropRules::text($site['name'] ?? null, 128) ?? $siteId,
            'description' => InteropRules::text($site['description'] ?? null, 2000),
            'domain' => $host,
            'origin' => $origin,
            'manifest_url' => $manifestUrl,
            'document' => $document,
            'etag' => $etag,
        ];
    }
}
