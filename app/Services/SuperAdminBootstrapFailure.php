<?php

declare(strict_types=1);

namespace App\Services;

use PDOException;
use Throwable;

/**
 * Why a non-interactive Super Admin bootstrap failed.
 *
 * The console line is built only from this code and the exit code. The
 * database exception message and its SQL bindings stay out of the log,
 * because those bindings include the email.
 */
enum SuperAdminBootstrapFailure: string
{
    case MissingVariable = 'missing_variable';
    case InvalidEmail = 'invalid_email';
    case HashRejected = 'hash_rejected';
    case DatabaseUnreachable = 'database_unreachable';
    case TablesMissing = 'tables_missing';
    case SuperAdminExists = 'super_admin_exists';
    case DuplicateEmail = 'duplicate_email';
    case UnexpectedError = 'unexpected_error';

    /**
     * One console line. It does not include the exception text.
     */
    public function line(int $exit_code): string
    {
        $line = 'Super Admin bootstrap failed: '.$this->value.' exit='.$exit_code;

        if ($this === self::TablesMissing) {
            $line .= '. run migrations first.';
        }

        return $line;
    }

    /**
     * Classify a throwable from its SQLSTATE and driver code.
     *
     * The message is not read. A failed query interpolates the email into
     * that message, and the log must not contain it.
     */
    public static function fromThrowable(Throwable $exception): self
    {
        $sqlstate = self::sqlstate($exception);
        $driver_code = self::driverCode($exception);

        if ($sqlstate === '42S02' || $driver_code === 1146) {
            return self::TablesMissing;
        }

        if ($sqlstate === '23000' || $driver_code === 1062) {
            return self::DuplicateEmail;
        }

        if (self::isUnreachable($sqlstate, $driver_code)) {
            return self::DatabaseUnreachable;
        }

        return self::UnexpectedError;
    }

    /**
     * SQLSTATE from this throwable or the one it wraps.
     */
    private static function sqlstate(Throwable $exception): ?string
    {
        $current = $exception;

        while ($current instanceof Throwable) {
            if ($current instanceof PDOException && is_array($current->errorInfo)) {
                $state = $current->errorInfo[0] ?? null;

                if (is_string($state) && $state !== '') {
                    return $state;
                }
            }

            $code = $current->getCode();

            if (is_string($code) && preg_match('/^[0-9A-Z]{5}$/', $code) === 1) {
                return $code;
            }

            $current = $current->getPrevious();
        }

        return null;
    }

    /**
     * Driver-specific error number, such as MySQL 1146 or 2002.
     */
    private static function driverCode(Throwable $exception): ?int
    {
        $current = $exception;

        while ($current instanceof Throwable) {
            if ($current instanceof PDOException && is_array($current->errorInfo)) {
                $code = $current->errorInfo[1] ?? null;

                if (is_int($code)) {
                    return $code;
                }

                if (is_string($code) && preg_match('/^\d+$/', $code) === 1) {
                    return (int) $code;
                }
            }

            $code = $current->getCode();

            if (is_int($code) && $code > 0) {
                return $code;
            }

            if (is_string($code) && preg_match('/^\d+$/', $code) === 1) {
                return (int) $code;
            }

            $current = $current->getPrevious();
        }

        return null;
    }

    /**
     * True when the database connection itself failed.
     */
    private static function isUnreachable(?string $sqlstate, ?int $driver_code): bool
    {
        if (in_array($driver_code, [2002, 2003, 2005, 2006, 2013], true)) {
            return true;
        }

        return is_string($sqlstate) && str_starts_with($sqlstate, '08');
    }
}
