<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A temporary password for a new student account.
 *
 * The alphabet leaves out characters that are easy to confuse.
 */
final class TemporaryPassword
{
    public const int LENGTH = 12;

    public const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    /**
     * Twelve characters, with at least one letter and one digit.
     */
    public static function generate(): string
    {
        $last = strlen(self::ALPHABET) - 1;

        do {
            $password = '';

            for ($index = 0; $index < self::LENGTH; $index++) {
                $password .= self::ALPHABET[random_int(0, $last)];
            }
        } while (preg_match('/\p{L}/u', $password) !== 1 || preg_match('/\d/', $password) !== 1);

        return $password;
    }
}
