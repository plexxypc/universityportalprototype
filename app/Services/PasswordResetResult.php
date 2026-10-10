<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Outcome of one password-reset submission.
 *
 * Failure carries no broker status. Callers show one friendly message.
 */
final class PasswordResetResult
{
    private function __construct(private readonly string $outcome) {}

    /**
     * The password was stored and the sessions were ended.
     */
    public static function reset(): self
    {
        return new self('reset');
    }

    /**
     * The token, the account, or the throttle did not allow a change.
     */
    public static function failed(): self
    {
        return new self('failed');
    }

    /**
     * The new password does not meet the portal rules.
     */
    public static function passwordRejected(): self
    {
        return new self('password_rejected');
    }

    /**
     * Whether the password was changed.
     */
    public function resetSucceeded(): bool
    {
        return $this->outcome === 'reset';
    }

    /**
     * Whether the new password was rejected.
     */
    public function passwordWasRejected(): bool
    {
        return $this->outcome === 'password_rejected';
    }
}
