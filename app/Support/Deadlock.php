<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * Run a transaction again when MySQL reports a deadlock.
 *
 * The victim's transaction is already rolled back. A later attempt can commit.
 */
final class Deadlock
{
    /**
     * Call the action. Try again when the database reports a deadlock.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    public static function attempt(callable $action, int $attempts = 3): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $action();
            } catch (QueryException $exception) {
                $attempt++;

                if (! self::is($exception) || $attempt >= $attempts) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * True when MySQL rolled this transaction back because of a deadlock.
     */
    public static function is(QueryException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? '');
        $code = (int) ($exception->errorInfo[1] ?? 0);

        return $state === '40001' || $code === 1213;
    }
}
