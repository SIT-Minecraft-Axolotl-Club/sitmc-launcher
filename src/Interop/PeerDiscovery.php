<?php

namespace SITMinecraft\Launcher\Interop;

/**
 * Resolves a friend site from its root domain alone.
 *
 * The domain is enough: the peer is expected to serve its SiteDocument at
 * `https://{domain}/.well-known/mc-interop-v1.json`. The document's `site`
 * block supplies the stable site id, display name and description, so the
 * administrator never has to type them by hand.
 */
final class PeerDiscovery
{
    public function __construct(private PeerFetcher $fetcher) {}

    /**
     * Deterministic placeholder id used while a peer is offline. It is replaced
     * by the real site id as soon as the first successful pull happens.
     */
    public function pendingId(string $domain): string
    {
        $host = InteropRules::host($domain) ?? $domain;

        return 'pending-'.substr(hash('sha256', $host), 0, 16);
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
    public function discover(string $domain): array
    {
        $host = InteropRules::host($domain);

        if ($host === null) {
            return ['ok' => false, 'error' => 'peer-domain-invalid'];
        }

        $origin = 'https://'.$host;
        $manifestUrl = $origin.InteropRules::WELL_KNOWN_PATH;
        $fetched = $this->fetcher->get($manifestUrl);

        if ($fetched['error'] !== null) {
            $error = str_starts_with($fetched['error'], 'peer-http-')
                ? 'peer-http-error'
                : $fetched['error'];

            return ['ok' => false, 'error' => $error];
        }

        if ($fetched['status'] !== 200 || $fetched['body'] === null) {
            return ['ok' => false, 'error' => 'peer-http-error'];
        }

        $document = json_decode($fetched['body'], true);

        if (! is_array($document)) {
            return ['ok' => false, 'error' => 'peer-json-invalid'];
        }

        return $this->fromDocument($document, $host, $fetched['etag']);
    }

    /**
     * Pure document validation, separated from the HTTP fetch so it can be
     * exercised without a network peer.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function fromDocument(array $document, string $host, ?string $etag = null): array
    {
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
            'origin' => 'https://'.$host,
            'manifest_url' => 'https://'.$host.InteropRules::WELL_KNOWN_PATH,
            'document' => $document,
            'etag' => $etag,
        ];
    }
}
