<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Formats and parses Naira amounts stored as integer kobo.
 *
 * Display splits the integer with division and remainder. The stored value
 * never passes through a float, including amounts above 2^53.
 */
final class Money
{
    private const string NAIRA_SIGN = '₦';

    /**
     * Format integer kobo as Naira, for example ₦125,000.00.
     *
     * Negative amounts are shown with a leading minus. Payment collection
     * must use paymentAmount(), which rejects zero and negatives.
     */
    public static function format(int $kobo): string
    {
        if ($kobo < 0) {
            if ($kobo === PHP_INT_MIN) {
                throw new InvalidArgumentException('Amount is too large.');
            }

            return '-'.self::format(-$kobo);
        }

        $naira = intdiv($kobo, 100);
        $fraction = $kobo % 100;

        return self::NAIRA_SIGN
            .self::groupDigits((string) $naira)
            .'.'
            .str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Parse a typed Naira string into integer kobo.
     *
     * Zero is valid. More than two decimal places is rejected with no rounding.
     * A leading minus is kept so callers can apply paymentAmount() separately.
     */
    public static function parseNaira(string $input): int
    {
        $normalized = str_replace(
            ['₦', ',', ' ', "\u{00A0}"],
            '',
            trim($input),
        );

        if (in_array($normalized, ['', '-', '.'], true)) {
            throw new InvalidArgumentException('Enter an amount in naira.');
        }

        if (! preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $normalized, $matches)) {
            throw new InvalidArgumentException('Enter an amount in naira, with at most two decimal places.');
        }

        $fraction = $matches[3] ?? '';

        if (strlen($fraction) > 2) {
            throw new InvalidArgumentException('Enter an amount with at most two decimal places.');
        }

        $kobo = self::integerFromDigits($matches[2].str_pad($fraction, 2, '0', STR_PAD_RIGHT));
        $negative = $matches[1] === '-';

        if ($negative && $kobo !== 0) {
            return -$kobo;
        }

        return $kobo;
    }

    /**
     * Accept a payment amount only when it is a positive number of kobo.
     */
    public static function paymentAmount(int $kobo): int
    {
        if ($kobo < 1) {
            throw new InvalidArgumentException('Payment amount must be a positive number of kobo.');
        }

        return $kobo;
    }

    /**
     * Insert thousands separators into a non-negative integer digit string.
     */
    private static function groupDigits(string $digits): string
    {
        $length = strlen($digits);

        if ($length <= 3) {
            return $digits;
        }

        $lead = $length % 3;

        if ($lead === 0) {
            $lead = 3;
        }

        $parts = [substr($digits, 0, $lead)];

        for ($index = $lead; $index < $length; $index += 3) {
            $parts[] = substr($digits, $index, 3);
        }

        return implode(',', $parts);
    }

    /**
     * Convert a digit string to int without using a float.
     */
    private static function integerFromDigits(string $digits): int
    {
        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return 0;
        }

        $limit = (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new InvalidArgumentException('Amount is too large.');
        }

        return (int) $digits;
    }
}
