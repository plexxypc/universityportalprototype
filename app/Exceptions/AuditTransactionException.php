<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * AuditService::record was called with no open transaction.
 */
final class AuditTransactionException extends RuntimeException
{
    public const string MESSAGE = 'Audit write requires an open transaction.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
