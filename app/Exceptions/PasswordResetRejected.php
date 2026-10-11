<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The new password failed the portal rules after the token matched.
 *
 * The message is a fixed code. It does not include the password or the token.
 */
final class PasswordResetRejected extends RuntimeException
{
    public const string MESSAGE = 'password_rejected';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
