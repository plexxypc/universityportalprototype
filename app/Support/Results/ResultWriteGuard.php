<?php

declare(strict_types=1);

namespace App\Support\Results;

/**
 * Lets one amendment write a published result.
 *
 * Every other update or delete of a published row is refused by the model.
 */
final class ResultWriteGuard
{
    public const string MESSAGE = 'A published result cannot be changed.';

    private static int $depth = 0;

    /**
     * True while an amendment is writing this result.
     */
    public static function amending(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Run the amendment writes. Nested calls stay inside the same bypass.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    public static function run(callable $action): mixed
    {
        self::$depth++;

        try {
            return $action();
        } finally {
            self::$depth--;
        }
    }
}
