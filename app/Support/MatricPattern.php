<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Matric pattern tokens from PRD section 6.5.
 *
 * {SEQ} prints the counter with no zero padding. {SEQ4} pads it to four
 * digits and does not shorten a number past 9999. Generation uses these
 * rules in TASK-064. The counter key must follow counterParts().
 */
final class MatricPattern
{
    public const string TOKEN_DEPARTMENT = '{DEPT}';

    public const string TOKEN_YEAR = '{YEAR}';

    public const string TOKEN_SEQUENCE = '{SEQ}';

    public const string TOKEN_SEQUENCE_PADDED = '{SEQ4}';

    public const int PADDED_WIDTH = 4;

    /**
     * Screen help for the two sequence tokens and the required pieces.
     */
    public static function help(): string
    {
        return '{DEPT} is the department code. {YEAR} is the four-digit year. '
            .'{SEQ} is the sequence with no zero padding, for example 1, 2, 3. '
            .'{SEQ4} is that sequence padded with zeros to four digits, for example 0001. '
            .'A number past 9999 is kept in full. Use one sequence token, and include {DEPT} or {YEAR}.';
    }

    /**
     * Shown on the settings screen. Saving a pattern does not rewrite issued numbers.
     */
    public static function notice(): string
    {
        return 'Changing the matric pattern does not change matric numbers that have already been issued.';
    }

    /**
     * Why a pattern is rejected, or null when it is allowed.
     */
    public static function error(string $pattern): ?string
    {
        $pattern = trim($pattern);

        if ($pattern === '' || strlen($pattern) > 64) {
            return 'Enter a matric pattern of at most 64 characters.';
        }

        $padded = substr_count($pattern, self::TOKEN_SEQUENCE_PADDED);
        $without_padded = str_replace(self::TOKEN_SEQUENCE_PADDED, '', $pattern);
        $plain = substr_count($without_padded, self::TOKEN_SEQUENCE);

        if ($padded + $plain !== 1) {
            return 'The matric pattern must include one sequence token, {SEQ} or {SEQ4}.';
        }

        if (! str_contains($pattern, self::TOKEN_DEPARTMENT) && ! str_contains($pattern, self::TOKEN_YEAR)) {
            return 'The matric pattern must include {DEPT} or {YEAR}.';
        }

        $literal = str_replace(
            [
                self::TOKEN_DEPARTMENT,
                self::TOKEN_YEAR,
                self::TOKEN_SEQUENCE_PADDED,
                self::TOKEN_SEQUENCE,
            ],
            '',
            $pattern,
        );

        if (preg_match('/^[A-Za-z0-9.\/-]*$/', $literal) !== 1) {
            return 'The matric pattern contains a character or token that is not allowed.';
        }

        return null;
    }

    /**
     * Which counter parts this pattern uses.
     *
     * TASK-064 must build the counters key from these flags. A pattern with
     * only a year must not share a counter with a pattern that also has a
     * department, or two departments can receive the same matric number.
     *
     * @return array{department: bool, year: bool, padded: bool}
     */
    public static function counterParts(string $pattern): array
    {
        $error = self::error($pattern);

        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }

        return [
            'department' => str_contains($pattern, self::TOKEN_DEPARTMENT),
            'year' => str_contains($pattern, self::TOKEN_YEAR),
            'padded' => str_contains($pattern, self::TOKEN_SEQUENCE_PADDED),
        ];
    }

    /**
     * The sequence text {SEQ} or {SEQ4} produces for one counter value.
     */
    public static function formatSequence(int $value, string $pattern): string
    {
        if ($value < 1) {
            throw new InvalidArgumentException('The sequence must be at least 1.');
        }

        $parts = self::counterParts($pattern);

        if (! $parts['padded']) {
            return (string) $value;
        }

        $digits = (string) $value;

        if (strlen($digits) >= self::PADDED_WIDTH) {
            return $digits;
        }

        return str_pad($digits, self::PADDED_WIDTH, '0', STR_PAD_LEFT);
    }
}
