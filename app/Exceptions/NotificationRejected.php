<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A notification was refused before any row was written.
 *
 * The message is one of the fixed codes. It does not include the submitted
 * type, title, message, link, or any other field.
 */
final class NotificationRejected extends RuntimeException
{
    public const string UNKNOWN_TYPE = 'unknown_notification_type';

    public const string INVALID_DATA = 'invalid_notification_data';

    public const string INVALID_LINK = 'invalid_notification_link';

    /**
     * Refuse an unknown notification type.
     */
    public static function unknownType(): self
    {
        return new self(self::UNKNOWN_TYPE);
    }

    /**
     * Refuse a payload that is not title, message, and an optional link.
     */
    public static function invalidData(): self
    {
        return new self(self::INVALID_DATA);
    }

    /**
     * Refuse a link that is not a same-site relative path.
     */
    public static function invalidLink(): self
    {
        return new self(self::INVALID_LINK);
    }
}
