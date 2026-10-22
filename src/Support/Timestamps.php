<?php

namespace SITMinecraft\Launcher\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Converts between stored timestamps and the strings the launcher and the admin
 * forms use.
 *
 * Two very different shapes are needed for the same value: the launcher only
 * accepts ISO-8601 with an explicit timezone (it runs on the player's machine,
 * whose clock and timezone are unrelated to the site's), while `datetime-local`
 * inputs in the admin forms are always wall-clock strings in the site's
 * timezone. The database stores the latter, so every conversion goes through
 * here instead of being repeated per caller.
 *
 * The timezone always defaults to PHP's configured one, which Laravel sets to
 * the application timezone while booting.
 */
final class Timestamps
{
    /**
     * Unix timestamp of a stored value, or null when it is absent or cannot be
     * parsed. Callers use null to mean "the launcher would reject this".
     */
    public static function value(mixed $value, ?string $timezone = null): ?int
    {
        return self::parse($value, $timezone)?->getTimestamp();
    }

    /**
     * ISO-8601 string in UTC, the only shape the launcher's `Date.parse` is
     * given. Null when the value is absent or unparseable, which the client
     * accepts for the optional fields but not for the required ones.
     */
    public static function isoUtc(mixed $value, ?string $timezone = null): ?string
    {
        return self::parse($value, $timezone)
            ?->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Value of a `datetime-local` input, in the site's timezone, so the admin
     * form shows back what was entered instead of a converted instant.
     */
    public static function inputValue(mixed $value, ?string $timezone = null): ?string
    {
        return self::parse($value, $timezone)?->format('Y-m-d\TH:i');
    }

    /**
     * Normalises a submitted form value into the wall-clock string the database
     * column holds. An offset in the input is honoured and then converted.
     */
    public static function fromInput(mixed $value, ?string $timezone = null): ?string
    {
        return self::parse($value, $timezone)?->format('Y-m-d H:i:s');
    }

    private static function parse(mixed $value, ?string $timezone): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, self::zone($timezone));
        } catch (Throwable) {
            return null;
        }
    }

    private static function zone(?string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone ?? date_default_timezone_get());
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}
