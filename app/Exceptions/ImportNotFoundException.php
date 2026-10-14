<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The staged import is missing, forged, or owned by someone else.
 *
 * Callers answer every case with the same 404.
 */
final class ImportNotFoundException extends RuntimeException
{
    public const string MESSAGE = 'That import was not found.';

    public static function missing(): self
    {
        return new self(self::MESSAGE);
    }
}
