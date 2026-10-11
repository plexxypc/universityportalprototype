<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * Map a provider HTTP status to an outbox outcome.
 *
 * Classification uses the status code only. HTTP 401 and 403 advance the
 * circuit breaker. HTTP 400 and 422 fail that recipient and do not pause
 * sending (ADR-029 amendment, 2026-10-10).
 */
final class ProviderStatus
{
    public const string KIND_SUCCESS = 'success';

    public const string KIND_PERMANENT = 'permanent';

    public const string KIND_AUTH = 'auth';

    public const string KIND_QUOTA = 'quota';

    public const string KIND_TEMPORARY = 'temporary';

    /**
     * Classify one status code.
     *
     * @return array{kind: string, error: string|null}
     */
    public function classify(int $status_code): array
    {
        if ($status_code >= 200 && $status_code < 300) {
            return ['kind' => self::KIND_SUCCESS, 'error' => null];
        }

        if ($status_code === 400) {
            return ['kind' => self::KIND_PERMANENT, 'error' => MailError::INVALID_RECIPIENT];
        }

        if ($status_code === 422) {
            return ['kind' => self::KIND_PERMANENT, 'error' => MailError::PROVIDER_REJECTED];
        }

        if ($status_code === 401 || $status_code === 403) {
            return ['kind' => self::KIND_AUTH, 'error' => MailError::PROVIDER_AUTH];
        }

        if ($status_code === 402) {
            return ['kind' => self::KIND_QUOTA, 'error' => MailError::DAILY_LIMIT];
        }

        if ($status_code === 0 || $status_code === 408 || $status_code === 429 || ($status_code >= 500 && $status_code <= 599)) {
            return ['kind' => self::KIND_TEMPORARY, 'error' => MailError::PROVIDER_UNAVAILABLE];
        }

        return ['kind' => self::KIND_PERMANENT, 'error' => MailError::PROVIDER_REJECTED];
    }
}
