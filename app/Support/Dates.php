<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Stringable;

/**
 * Formats dates for display in Africa/Lagos.
 *
 * A value that already has a timezone, including UTC, is converted.
 * A naive string is read in the given source timezone, or in the
 * application timezone when none is given, and is not shifted again.
 */
final class Dates
{
    public const string TIMEZONE = 'Africa/Lagos';

    private const string EMPTY = '—';

    /**
     * Format a calendar date as 05 Oct 2026, or an em dash when missing.
     */
    public static function date(mixed $value, ?string $source_timezone = null): string
    {
        $instant = self::toLagos($value, $source_timezone);

        if ($instant === null) {
            return self::EMPTY;
        }

        return $instant->format('d M Y');
    }

    /**
     * Format a date and time as 05 Oct 2026, 14:30, or an em dash when missing.
     */
    public static function dateTime(mixed $value, ?string $source_timezone = null): string
    {
        $instant = self::toLagos($value, $source_timezone);

        if ($instant === null) {
            return self::EMPTY;
        }

        return $instant->format('d M Y, H:i');
    }

    /**
     * Convert a stored instant to Africa/Lagos, or null when it cannot be read.
     */
    private static function toLagos(mixed $value, ?string $source_timezone): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone(self::TIMEZONE));
        }

        if (is_int($value)) {
            return (new DateTimeImmutable('@'.$value))
                ->setTimezone(new DateTimeZone(self::TIMEZONE));
        }

        if (! is_string($value) && ! $value instanceof Stringable) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        try {
            $source = new DateTimeZone($source_timezone ?? self::applicationTimezone());
            $parsed = new DateTimeImmutable($text, $source);
        } catch (Exception) {
            return null;
        }

        return $parsed->setTimezone(new DateTimeZone(self::TIMEZONE));
    }

    /**
     * Timezone used when a naive string does not name its own source.
     */
    private static function applicationTimezone(): string
    {
        if (! function_exists('config')) {
            return self::TIMEZONE;
        }

        $configured = config('app.timezone');

        if (! is_string($configured) || $configured === '') {
            return self::TIMEZONE;
        }

        return $configured;
    }
}
