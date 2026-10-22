<?php

namespace SITMinecraft\Launcher\Interop;

use SITMinecraft\Launcher\Support\Timestamps;

/**
 * The field rules shared by the mc-interop v1 serializer, the peer merger and
 * the admin forms.
 *
 * v1 is a deliberately small document: one flat `packs` array of instances
 * (pack + server + version), a self-describing `site`, a friend-only `peers`
 * list and one top-level `updatedAt`. Unknown loader/format values are
 * fail-closed: the instance is not published rather than being guessed at.
 */
final class InteropRules
{
    public const WELL_KNOWN_PATH = '/.well-known/mc-interop-v1.json';

    public const MAX_DOCUMENT_BYTES = 262144;

    public const MAX_PEER_BYTES = 1048576;

    public const LOADERS = ['vanilla', 'fabric', 'forge', 'neoforge', 'quilt'];

    public const FORMATS = ['mrpack', 'multimc', 'curseforge-zip', 'server-zip', 'raw'];

    public const DISTRIBUTION_SYSTEMS = ['yda-launcher-v2', 'yap-mip'];

    public static function loader(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $loader = strtolower(trim((string) $value));

        return in_array($loader, self::LOADERS, true) ? $loader : null;
    }

    public static function format(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $format = strtolower(trim((string) $value));

        return in_array($format, self::FORMATS, true) ? $format : null;
    }

    public static function distributionSystem(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $system = strtolower(trim((string) $value));

        return in_array($system, self::DISTRIBUTION_SYSTEMS, true) ? $system : null;
    }

    /**
     * Stable identifier for an instance / site. The charset is shared with the
     * legacy manifest and is safe to interpolate into the artifact URL.
     */
    public static function id(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $id = trim((string) $value);

        if ($id === '' || strlen($id) > 128) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._-]+$/', $id) === 1 ? $id : null;
    }

    /**
     * The site/friend root domain: a bare host, no scheme, port or path.
     * A single label (`localhost`) is accepted so local previews still build.
     */
    public static function host(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $host = strtolower(rtrim(trim((string) $value), '.'));

        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        if (preg_match('/[\s\/:@?#]/', $host) === 1) {
            return null;
        }

        $pattern = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?'
            .'(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/';

        return preg_match($pattern, $host) === 1 ? $host : null;
    }

    public static function text(mixed $value, int $max = 512): ?string
    {
        if (! is_scalar($value) && ! $value instanceof \Stringable) {
            return null;
        }

        $text = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $value) ?? '');

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $max);
    }

    /**
     * Absolute HTTPS URL without embedded credentials, or null.
     */
    public static function httpsUrl(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $url = trim((string) $value);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }

    /**
     * Absolute HTTPS URL on a public host. Artifact URLs must be public (§10),
     * and peers are fetched by this server, so this also acts as the SSRF gate.
     * Hostnames are not resolved here: literal private IPs and `.local` names
     * are rejected while a hostname resolving to private space remains the
     * administrator's responsibility.
     */
    public static function publicHttpsUrl(mixed $value): ?string
    {
        $url = self::httpsUrl($value);

        if ($url === null) {
            return null;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        return self::publicHost($host) ? $url : null;
    }

    public static function publicHost(string $host): bool
    {
        $host = strtolower(rtrim(trim($host), '.'));

        if ($host === '') {
            return false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        foreach (['.local', '.internal', '.home.arpa', '.lan'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        if (str_contains($host, '%')) {
            return false;
        }

        $ip = trim($host, '[]');

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::publicIpv4($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::publicIpv6($ip);
        }

        return true;
    }

    public static function sha1(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $digest = strtolower(trim((string) $value));

        return preg_match('/^[0-9a-f]{40}$/', $digest) === 1 ? $digest : null;
    }

    public static function sha512(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $digest = strtolower(trim((string) $value));

        return preg_match('/^[0-9a-f]{128}$/', $digest) === 1 ? $digest : null;
    }

    public static function size(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $size = (int) $value;

        return $size > 0 ? $size : null;
    }

    public static function port(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $port = (int) $value;

        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    public static function revision(mixed $value): ?int
    {
        if (is_bool($value) || ! is_numeric($value)) {
            return null;
        }

        $revision = (int) $value;

        return $revision >= 0 ? $revision : null;
    }

    public static function timestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (! is_string($value) && ! $value instanceof \DateTimeInterface) {
            return null;
        }

        if (is_string($value) && preg_match('/^\d{9,12}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return Timestamps::value($value);
    }

    public static function isoUtc(?int $timestamp): ?string
    {
        return $timestamp === null ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public static function encode(array $document): string
    {
        $encoded = json_encode(
            $document,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return $encoded === false ? '{}' : $encoded;
    }

    public static function etag(string $payload): string
    {
        return '"'.sha1($payload).'"';
    }

    public static function ifNoneMatch(?string $header, string $etag): bool
    {
        $header = trim((string) $header);

        if ($header === '') {
            return false;
        }

        if ($header === '*') {
            return true;
        }

        $normalize = static function (string $tag): string {
            $tag = trim($tag);

            return str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;
        };

        foreach (explode(',', $header) as $candidate) {
            if ($normalize($candidate) === $etag) {
                return true;
            }
        }

        return false;
    }

    private static function publicIpv4(string $ip): bool
    {
        $long = ip2long($ip);

        if ($long === false) {
            return false;
        }

        $blocked = [
            [0x00000000, 0xFF000000],
            [0x0A000000, 0xFF000000],
            [0x7F000000, 0xFF000000],
            [0x64400000, 0xFFC00000],
            [0xAC100000, 0xFFF00000],
            [0xC0A80000, 0xFFFF0000],
            [0xA9FE0000, 0xFFFF0000],
            [0xC0000000, 0xFFFFFF00],
            [0xC6120000, 0xFFFE0000],
            [0xE0000000, 0xF0000000],
            [0xF0000000, 0xF0000000],
        ];

        foreach ($blocked as [$base, $mask]) {
            if (($long & $mask) === $base) {
                return false;
            }
        }

        return true;
    }

    private static function publicIpv6(string $ip): bool
    {
        $packed = inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if ($packed === inet_pton('::1') || $packed === inet_pton('::')) {
            return false;
        }

        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $mapped = inet_ntop(substr($packed, 12));

            return $mapped !== false && self::publicIpv4($mapped);
        }

        $first = ord($packed[0]);
        $second = ord($packed[1]);

        if (($first & 0xFE) === 0xFC) {
            return false;
        }

        if ($first === 0xFE && ($second & 0xC0) === 0x80) {
            return false;
        }

        return true;
    }
}
