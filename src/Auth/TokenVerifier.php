<?php

namespace SITMinecraft\Launcher\Auth;

/**
 * Verifies the launcher's Yggdrasil Connect token and reports the character it is
 * bound to.
 *
 * The launcher signs in with the site's OpenID provider (Janus) and keeps the
 * access token it receives; the same token already authenticates the player to
 * the game servers. The site's own userinfo endpoint is the authority on whether
 * that token is still valid, so it is asked to verify it here instead of
 * reimplementing token validation: the endpoint answers 401 for an invalid token
 * and returns the bound character otherwise.
 *
 * Only the character name is taken from it. The launcher has no way to name the
 * account, and the plugin has no business assuming how this site derives player
 * UUIDs, so callers look the character up by name and check its owner.
 */
final class TokenVerifier
{
    private const TIMEOUT_SECONDS = 5;

    public function __construct(private string $endpoint) {}

    /**
     * The bound character name, or null when the token is unusable.
     */
    public function verifiedPlayer(string $token): ?string
    {
        $response = $this->request($token);
        if ($response === null) {
            return null;
        }

        $profile = $response['selectedProfile'] ?? null;
        if (!is_array($profile) || !isset($profile['name']) || !is_string($profile['name'])) {
            return null;
        }

        $name = trim($profile['name']);

        return $name === '' ? null : $name;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function request(string $token): ?array
    {
        $ch = curl_init($this->endpoint);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token,
                'Accept: application/json',
                'Accept-Language: zh-CN',
            ],
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($body) || $status !== 200) {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
