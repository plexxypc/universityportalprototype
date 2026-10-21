<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Payments cannot be used with the current configuration.
 *
 * The message is safe to show. It contains no setting and no secret.
 */
final class PaymentsUnavailableException extends RuntimeException
{
    public const string MESSAGE = 'Payments are unavailable.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
