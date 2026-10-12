<?php

declare(strict_types=1);

namespace App\Support\Grading;

use InvalidArgumentException;

/**
 * Converts a two-decimal money-style number and integer hundredths.
 *
 * 69.50 is 6950. The conversion does not use floating point.
 */
final class Hundredths
{
    /**
     * Turn "69.50", "69.5", or "40" into hundredths.
     */
    public static function fromDecimal(string $value): int
    {
        $value = trim($value);

        if (preg_match('/^\d+(\.\d{1,2})?$/', $value) !== 1) {
            throw new InvalidArgumentException('Use at most two decimal places.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return ((int) $whole * 100) + (int) $fraction;
    }

    /**
     * Turn hundredths into a two-decimal string such as "69.50".
     */
    public static function toDecimal(int $hundredths): string
    {
        $whole = intdiv($hundredths, 100);
        $fraction = $hundredths % 100;

        return $whole.'.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }
}
