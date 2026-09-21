<?php

namespace SITMinecraft\Launcher\Update;

/**
 * Semantic comparison of launcher versions.
 *
 * The updater asks a single question — "is the published build newer than the one
 * I am running?" — and the answer must not be string comparison, because
 * `1.10.0` is newer than `1.9.0` while sorting lower as text.
 *
 * A version that cannot be parsed returns null instead of a guessed answer: the
 * callers publish the release in that case, since refusing to update is worse
 * than offering an update that may not be newer.
 */
final class SemanticVersion
{
    /**
     * -1, 0 or 1 when both versions parse, null when either one does not.
     */
    public static function compare(string $left, string $right): ?int
    {
        $left = self::parse($left);
        $right = self::parse($right);

        if ($left === null || $right === null) {
            return null;
        }

        $length = max(count($left['release']), count($right['release']));

        for ($index = 0; $index < $length; $index++) {
            $result = ($left['release'][$index] ?? 0) <=> ($right['release'][$index] ?? 0);

            if ($result !== 0) {
                return $result;
            }
        }

        return self::comparePreRelease($left['pre'], $right['pre']);
    }

    /**
     * Null when the two versions cannot be ordered, which tells the caller to
     * answer with the update document rather than with "no update".
     */
    public static function isNewer(string $candidate, string $current): ?bool
    {
        $result = self::compare($candidate, $current);

        return $result === null ? null : $result > 0;
    }

    /**
     * @return array{release: array<int, int>, pre: array<int, string>}|null
     */
    private static function parse(string $version): ?array
    {
        $version = trim($version);

        if ($version === '') {
            return null;
        }

        // A leading "v" and build metadata are tolerated: they carry no
        // precedence, and rejecting them would only stop updates from shipping.
        $version = (string) preg_replace('/^[vV]/', '', $version);
        $version = explode('+', $version, 2)[0];

        $parts = explode('-', $version, 2);
        $release = [];

        foreach (explode('.', $parts[0]) as $component) {
            if (preg_match('/^\d+$/', $component) !== 1) {
                return null;
            }

            $release[] = (int) $component;
        }

        $pre = [];

        if (isset($parts[1]) && $parts[1] !== '') {
            $pre = explode('.', $parts[1]);

            foreach ($pre as $identifier) {
                if ($identifier === '') {
                    return null;
                }
            }
        }

        return ['release' => $release, 'pre' => array_values($pre)];
    }

    /**
     * @param  array<int, string>  $left
     * @param  array<int, string>  $right
     */
    private static function comparePreRelease(array $left, array $right): int
    {
        if ($left === [] || $right === []) {
            return $left === $right ? 0 : ($left === [] ? 1 : -1);
        }

        $length = max(count($left), count($right));

        for ($index = 0; $index < $length; $index++) {
            $a = $left[$index] ?? null;
            $b = $right[$index] ?? null;

            if ($a === null || $b === null) {
                return $a === null ? -1 : 1;
            }

            $aNumeric = preg_match('/^\d+$/', $a) === 1;
            $bNumeric = preg_match('/^\d+$/', $b) === 1;

            if ($aNumeric && $bNumeric) {
                $result = (int) $a <=> (int) $b;
            } elseif ($aNumeric !== $bNumeric) {
                $result = $aNumeric ? -1 : 1;
            } else {
                $result = strcmp($a, $b) <=> 0;
            }

            if ($result !== 0) {
                return $result;
            }
        }

        return 0;
    }
}
