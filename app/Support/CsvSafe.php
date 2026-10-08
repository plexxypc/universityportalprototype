<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;
use Stringable;

/**
 * Neutralises spreadsheet formula injection in CSV and Excel exports.
 *
 * A leading apostrophe is added when the cell starts with =, +, -, @,
 * a tab, or a carriage return. The same characters later in the value
 * are left unchanged.
 */
final class CsvSafe
{
    /**
     * Return a cell value that a spreadsheet will treat as text.
     */
    public static function cell(mixed $value): string
    {
        $text = self::stringify($value);

        if ($text === '') {
            return '';
        }

        $first = $text[0];

        if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$text;
        }

        return $text;
    }

    /**
     * Turn a non-string export value into text before the prefix check.
     */
    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return self::floatToString($value);
        }

        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_array($value)) {
            try {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return '';
            }
        }

        return '';
    }

    /**
     * Render a float as plain text. Money amounts must not use this path.
     */
    private static function floatToString(float $value): string
    {
        if (! is_finite($value)) {
            return '';
        }

        $text = rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');

        if ($text === '' || $text === '-') {
            return '0';
        }

        return $text;
    }
}
