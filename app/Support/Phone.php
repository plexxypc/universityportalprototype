<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Nigerian phone numbers stored as +234 and ten digits.
 *
 * Import validation (TASK-075) uses this same method.
 */
final class Phone
{
    public const string MESSAGE = 'Enter a Nigerian phone number, for example 08012345678.';

    /**
     * Return the canonical number, or null when the value is blank.
     *
     * @throws InvalidArgumentException
     */
    public static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $compact = preg_replace('/[\s().-]+/', '', $trimmed);
        $compact = is_string($compact) ? $compact : $trimmed;

        if (preg_match('/^\+234(\d{10})$/', $compact, $matches) === 1) {
            return '+234'.$matches[1];
        }

        if (preg_match('/^234(\d{10})$/', $compact, $matches) === 1) {
            return '+234'.$matches[1];
        }

        if (preg_match('/^0(\d{10})$/', $compact, $matches) === 1) {
            return '+234'.$matches[1];
        }

        if (preg_match('/^(\d{10})$/', $compact, $matches) === 1) {
            return '+234'.$matches[1];
        }

        throw new InvalidArgumentException(self::MESSAGE);
    }
}
