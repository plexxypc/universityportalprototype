<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Run a transaction again when MySQL reports a deadlock.
 *
 * The victim's transaction is already rolled back. A later attempt can commit.
 */
final class Deadlock
{
    public const string MESSAGE = 'This could not be saved because the database was busy. Try again.';

    /**
     * Call the action. Try again when the database reports a deadlock.
     *
     * Any other database error is thrown at once. After the last deadlock
     * the caller gets one fixed message. The failed attempt is already
     * rolled back, so it leaves no rows.
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

                if (! self::is($exception)) {
                    throw $exception;
                }

                if ($attempt >= $attempts) {
                    throw ValidationException::withMessages([
                        'database' => self::MESSAGE,
                    ]);
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
