<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Allowed email template names.
 *
 * Stored values are snake_case. Credentials and password reset are the only
 * secret templates. The test template stays for the outbox send path.
 */
enum EmailTemplate: string
{
    case Credentials = 'credentials';
    case PasswordReset = 'password_reset';
    case Admission = 'admission';
    case RegistrationConfirmed = 'registration_confirmed';
    case PaymentConfirmed = 'payment_confirmed';
    case ResultPublished = 'result_published';
    case Announcement = 'announcement';
    case TimetablePublished = 'timetable_published';
    case Test = 'test';

    /**
     * Whether the rendered body is a secret until send.
     */
    public function isSecret(): bool
    {
        return $this === self::Credentials || $this === self::PasswordReset;
    }

    /**
     * Keys the sender must supply. A missing or blank key refuses the send.
     *
     * @return list<string>
     */
    public function requiredKeys(): array
    {
        return match ($this) {
            self::Credentials => ['name', 'login_id', 'temporary_password', 'expires_at'],
            self::PasswordReset => ['name', 'token'],
            self::Admission => ['name', 'programme'],
            self::RegistrationConfirmed => ['name', 'session_name', 'semester_name', 'total_units'],
            self::PaymentConfirmed => ['name', 'amount_kobo', 'receipt_number', 'paid_at'],
            self::ResultPublished => ['name', 'session_name', 'semester_name'],
            self::Announcement => ['title', 'message'],
            self::TimetablePublished => ['name', 'session_name', 'semester_name'],
            self::Test => ['message'],
        };
    }

    /**
     * Fields that stay encrypted until send.
     *
     * @return list<string>
     */
    public function secretKeys(): array
    {
        return match ($this) {
            self::Credentials => ['temporary_password'],
            self::PasswordReset => ['token'],
            default => [],
        };
    }

    /**
     * Required keys that are integers. Zero is a present value.
     *
     * @return list<string>
     */
    public function integerKeys(): array
    {
        return match ($this) {
            self::RegistrationConfirmed => ['total_units'],
            self::PaymentConfirmed => ['amount_kobo'],
            default => [],
        };
    }

    /**
     * Required keys formatted with Dates.
     *
     * @return list<string>
     */
    public function dateKeys(): array
    {
        return match ($this) {
            self::Credentials => ['expires_at'],
            self::PaymentConfirmed => ['paid_at'],
            default => [],
        };
    }
}
