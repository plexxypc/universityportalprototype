<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An audit row was updated or deleted. Rows are insert-only.
 */
final class AuditLogImmutableException extends RuntimeException
{
    public const string UPDATE_MESSAGE = 'Audit logs cannot be updated.';

    public const string DELETE_MESSAGE = 'Audit logs cannot be deleted.';

    /**
     * Reject an update.
     */
    public static function forUpdate(): self
    {
        return new self(self::UPDATE_MESSAGE);
    }

    /**
     * Reject a delete.
     */
    public static function forDelete(): self
    {
        return new self(self::DELETE_MESSAGE);
    }
}
