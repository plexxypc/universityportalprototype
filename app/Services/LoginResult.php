<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Outcome of one portal login attempt.
 *
 * Failure carries no reason. Callers show one generic message.
 */
final class LoginResult
{
    private function __construct(
        public bool $succeeded,
        public ?string $redirect_to,
    ) {}

    /**
     * A failed attempt, with no redirect.
     */
    public static function failed(): self
    {
        return new self(false, null);
    }

    /**
     * A successful attempt that should continue to this path.
     */
    public static function succeeded(string $redirect_to): self
    {
        return new self(true, $redirect_to);
    }
}
