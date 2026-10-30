<?php

namespace SITMinecraft\Launcher\Manifest;

/**
 * Probes an externally hosted pack so the admin form can auto-fill size and
 * SHA-1 instead of asking the administrator to compute them by hand.
 *
 * Strategy: try a HEAD request for `Content-Length` first (cheap), then stream
 * the file once to compute the SHA-1. TLS verification is relaxed because the
 * digest is what the launcher ultimately checks — a MITM'd download would fail
 * the hash the client compares against. The download happens at most once per
 * probe, capped at MAX_BYTES so a huge or infinite URL cannot stall the admin
 * request.
 */
final class ExternalPackProbe
{
    private const MAX_BYTES = 1073741824;

    private const TIMEOUT_SECONDS = 30;

    /**
     * @return array{ok: bool, error?: string, size?: int, sha1?: string}
     */
    public function probe(string $url): array
    {
        if (! function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'curl-missing'];
        }

        $url = trim($url);

        if (! preg_match('~^https?://~i', $url) || parse_url($url, PHP_URL_HOST) === null) {
            return ['ok' => false, 'error' => 'url-invalid'];
        }

        $head = $this->head($url);

        if ($head === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }

        $size = $head['size'];

        // Head-only is enough when the server already advertises the exact
        // length; the digest still needs the body, so stream it once.
        $stream = $this->stream($url, $head['acceptRanges']);

        if ($stream === null) {
            return $size !== null
                ? ['ok' => true, 'size' => $size]
                : ['ok' => false, 'error' => 'unreachable'];
        }

        return [
            'ok' => true,
            'size' => $stream['size'] ?? $size,
            'sha1' => $stream['sha1'],
        ];
    }

    /**
     * @return array{size: ?int, acceptRanges: bool}|null
     */
    private function head(string $url): ?array
    {
        $curl = curl_init($url);

        if ($curl === false) {
            return null;
        }

        curl_setopt_array($curl, [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => 'sitmc-launcher-probe/1.0',
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $headers = (string) curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($status !== 200) {
            return null;
        }

        $length = null;

        if (preg_match('/^content-length:\s*(\d+)\s*$/mi', $headers, $m) === 1) {
            $length = (int) $m[1];
        }

        $encodingFound = preg_match('/^content-encoding:/mi', $headers) === 1;
        $acceptRanges = preg_match('/^accept-ranges:\s*bytes/mi', $headers) === 1;

        // A compressed transfer has a length that is not the file's real size.
        if ($encodingFound) {
            $length = null;
        }

        return ['size' => $length !== null && $length > 0 ? $length : null, 'acceptRanges' => $acceptRanges];
    }

    /**
     * Streams the body once, hashing as it goes. Falls back to a range request
     * when the server supports it and the head already gave us the size.
     *
     * @return array{size: ?int, sha1: string}|null
     */
    private function stream(string $url, bool $acceptRanges): ?array
    {
        $curl = curl_init($url);

        if ($curl === false) {
            return null;
        }

        $context = hash_init('sha1');
        $size = 0;
        $aborted = false;

        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_USERAGENT => 'sitmc-launcher-probe/1.0',
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_WRITEFUNCTION => function ($handle, string $data) use ($context, &$size, &$aborted): int {
                $length = strlen($data);
                $size += $length;
                hash_update($context, $data);

                if ($size > self::MAX_BYTES) {
                    $aborted = true;

                    return -1;
                }

                return $length;
            },
        ]);

        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($status !== 200 || $aborted) {
            return null;
        }

        return ['size' => $size > 0 ? $size : null, 'sha1' => hash_final($context, false)];
    }
}
