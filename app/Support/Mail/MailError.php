<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * Fixed outbox error codes.
 *
 * A code is stored as-is. It is never an address, a URL, a key, or a secret.
 */
final class MailError
{
    public const string INVALID_RECIPIENT = 'invalid_recipient';

    public const string PROVIDER_REJECTED = 'provider_rejected';

    public const string PROVIDER_AUTH = 'provider_auth';

    public const string DAILY_LIMIT = 'daily_limit';

    public const string PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const string OUTBOX_SEND_FAILED = 'outbox_send_failed';

    public const string SECRET_UNREADABLE = 'secret_unreadable';

    public const string SECRET_TEMPLATE_REFUSED = 'secret_template_refused';

    public const string MAILER_REJECTED = 'mailer_rejected';

    public const string MAIL_UNCONFIGURED = 'mail_unconfigured';

    public const string SUPERSEDED = 'superseded';

    public const string UNKNOWN_TEMPLATE = 'unknown_template';

    public const int MAX_ATTEMPTS = 5;

    public const int CODE_LIMIT = 180;

    /**
     * Keep a fixed code inside the column limit.
     */
    public static function clip(string $code): string
    {
        return substr($code, 0, self::CODE_LIMIT);
    }
}
