<?php

namespace SITMinecraft\Launcher\Interop;

/**
 * Fetches a peer's SiteDocument with the pull rules of §7.2: conditional
 * request, 15 second timeout and three backoff retries, capped at 1 MiB.
 *
 * cURL is used directly — like the plugin's token verifier — so the pull does
 * not add a Guzzle dependency to the host. Redirects are followed manually and
 * only within the same origin: a peer must not be able to turn this server into
 * an SSRF proxy by answering 302 to an internal address (§10).
 */
final class PeerFetcher
{
    private const MAX_REDIRECTS = 3;

    private const FALLBACK_PATH = '/api/launcher/interop/v1/site';

    public function __construct(
        private int $timeoutSeconds = 15,
        private int $attempts = 3,
        private int $maxBytes = InteropRules::MAX_PEER_BYTES,
        private string $userAgent = 'sitmc-launcher-interop/1.0',
    ) {
        $this->timeoutSeconds = max(1, $this->timeoutSeconds);
        $this->attempts = max(1, $this->attempts);
        $this->maxBytes = max(1024, $this->maxBytes);
    }

    /**
     * @return array{status: ?int, body: ?string, etag: ?string, error: ?string}
     */
    public function get(string $url, ?string $etag = null): array
    {
        $result = $this->fetch($url, $etag);

        // Some Nginx setups serve the well-known path through a dotfile 404
        // rule even though the plugin endpoint behind it works. Fall back to
        // the plugin-visible mirror, which returns the exact same document.
        if (
            $this->isWellKnown($url)
            && (
                in_array($result['status'], [403, 404], true)
                || in_array($result['error'], ['peer-http-403', 'peer-http-404'], true)
            )
        ) {
            return $this->fetch($this->fallbackUrl($url), $etag);
        }

        return $result;
    }

    /**
     * @return array{status: ?int, body: ?string, etag: ?string, error: ?string}
     */
    private function fetch(string $url, ?string $etag = null): array
    {
        $url = InteropRules::publicHttpsUrl($url);

        if ($url === null) {
            return ['status' => null, 'body' => null, 'etag' => null, 'error' => 'peer-url-invalid'];
        }

        if (! function_exists('curl_init')) {
            return ['status' => null, 'body' => null, 'etag' => null, 'error' => 'peer-curl-missing'];
        }

        $error = null;

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            [$result, $url] = $this->requestWithRedirects($url, $etag);

            if ($result['error'] !== null) {
                $error = $result['error'];

                if (
                    str_starts_with($error, 'peer-redirect')
                    || in_array($error, ['peer-document-too-large', 'peer-tls'], true)
                ) {
                    // A bad redirect, an oversized document or a broken trust
                    // store is a configuration error, not a transient failure;
                    // retrying would only repeat the same refusal.
                    return ['status' => null, 'body' => null, 'etag' => null, 'error' => $error];
                }
            } elseif ($result['status'] === 304) {
                return [
                    'status' => 304,
                    'body' => null,
                    'etag' => $result['etag'] !== null ? $result['etag'] : $etag,
                    'error' => null,
                ];
            } elseif ($result['status'] === 200) {
                $body = $result['body'] ?? '';

                if (strlen($body) > $this->maxBytes) {
                    return [
                        'status' => 200,
                        'body' => null,
                        'etag' => null,
                        'error' => 'peer-document-too-large',
                    ];
                }

                return [
                    'status' => 200,
                    'body' => $body,
                    'etag' => $result['etag'],
                    'error' => null,
                ];
            } else {
                $error = 'peer-http-'.(string) $result['status'];

                if ($result['status'] !== null && $result['status'] < 500 && $result['status'] !== 429) {
                    return ['status' => $result['status'], 'body' => null, 'etag' => null, 'error' => $error];
                }
            }

            if ($attempt < $this->attempts) {
                usleep(200000 * (2 ** ($attempt - 1)));
            }
        }

        return [
            'status' => null,
            'body' => null,
            'etag' => null,
            'error' => $error ?? 'peer-unreachable',
        ];
    }

    private function isWellKnown(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path === InteropRules::WELL_KNOWN_PATH;
    }

    private function fallbackUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (! is_string($host) || $host === '') {
            return $url;
        }

        return 'https://'.$host.($port === null ? '' : ':'.$port).self::FALLBACK_PATH;
    }

    /**
     * @return array{0: array{status: ?int, body: ?string, etag: ?string, error: ?string}, 1: string}
     */
    private function requestWithRedirects(string $url, ?string $etag): array
    {
        $redirects = 0;

        while (true) {
            $result = $this->requestOnce($url, $etag);

            if ($result['status'] === null || $result['status'] < 300 || $result['status'] >= 400) {
                return [$result, $url];
            }

            $location = $result['location'];

            if ($location === null || $location === '') {
                return [$result, $url];
            }

            if (++$redirects > self::MAX_REDIRECTS) {
                return [[
                    'status' => null,
                    'body' => null,
                    'etag' => null,
                    'error' => 'peer-redirect-limit',
                ], $url];
            }

            $next = $this->resolveRedirect($location, $url);

            if ($next === null) {
                return [[
                    'status' => null,
                    'body' => null,
                    'etag' => null,
                    'error' => 'peer-redirect-invalid',
                ], $url];
            }

            $url = $next;
        }
    }

    /**
     * @return array{status: ?int, body: ?string, etag: ?string, location: ?string, error: ?string}
     */
    private function requestOnce(string $url, ?string $etag): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return ['status' => null, 'body' => null, 'etag' => null, 'location' => null, 'error' => 'peer-connection'];
        }

        $responseEtag = null;
        $location = null;
        $body = '';
        $tooLarge = false;
        $limit = $this->maxBytes;

        $headers = [
            'Accept: application/json',
            'User-Agent: '.$this->userAgent,
        ];

        if ($etag !== null && trim($etag) !== '') {
            $headers[] = 'If-None-Match: '.$etag;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseEtag, &$location): int {
                if (stripos($line, 'ETag:') === 0) {
                    $responseEtag = trim(substr($line, 5));
                } elseif (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                }

                return strlen($line);
            },
            // Abort the transfer as soon as the body exceeds the 1 MiB cap, so
            // a hostile or broken peer cannot balloon this process's memory.
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $limit): int {
                if (strlen($body) + strlen($chunk) > $limit) {
                    $tooLarge = true;

                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
        ];

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        curl_setopt_array($handle, $options);

        $executed = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        curl_close($handle);

        if ($tooLarge) {
            return [
                'status' => $status > 0 ? $status : null,
                'body' => null,
                'etag' => $responseEtag,
                'location' => $location,
                'error' => 'peer-document-too-large',
            ];
        }

        if ($executed === false) {
            return [
                'status' => null,
                'body' => null,
                'etag' => null,
                'location' => null,
                'error' => self::curlError($errno),
            ];
        }

        return [
            'status' => $status > 0 ? $status : null,
            'body' => $body,
            'etag' => $responseEtag,
            'location' => $location,
            'error' => null,
        ];
    }

    private function resolveRedirect(string $location, string $current): ?string
    {
        $location = trim($location);

        if ($location === '') {
            return null;
        }

        $expected = parse_url($current);

        if (! is_array($expected) || ! isset($expected['host'])) {
            return null;
        }

        $scheme = 'https';
        $host = (string) $expected['host'];
        $port = isset($expected['port']) ? ':'.$expected['port'] : '';
        $origin = $scheme.'://'.$host.$port;
        $path = (string) ($expected['path'] ?? '/');

        if (str_starts_with($location, '//')) {
            $candidate = 'https:'.$location;
        } elseif (preg_match('#^https?://#i', $location) === 1) {
            $candidate = $location;
        } elseif (str_starts_with($location, '/')) {
            $candidate = $origin.$location;
        } elseif (str_starts_with($location, '?')) {
            $candidate = $origin.$path.$location;
        } else {
            $directory = substr($path, 0, (int) strrpos($path, '/') + 1);
            $candidate = $origin.($directory === '' ? '/' : $directory).$location;
        }

        $candidate = InteropRules::publicHttpsUrl($candidate);

        if ($candidate === null) {
            return null;
        }

        $actual = parse_url($candidate);

        if (! is_array($actual) || ! isset($actual['host'])) {
            return null;
        }

        $expectedPort = (int) ($expected['port'] ?? 443);
        $actualPort = (int) ($actual['port'] ?? 443);

        if (strtolower($host) !== strtolower((string) $actual['host']) || $expectedPort !== $actualPort) {
            return null;
        }

        return $candidate;
    }

    /**
     * A stable, non-leaking classification of a transport failure for the admin
     * diagnostics panel: the raw cURL message can include local paths and hosts.
     */
    private static function curlError(int $errno): string
    {
        return match ($errno) {
            28 => 'peer-timeout',
            35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 => 'peer-tls',
            default => 'peer-connection',
        };
    }
}
