<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ApplicantStatus;
use App\Enums\AttendanceStatus;
use App\Enums\EmailStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\ResultStatus;
use App\Enums\StudentStatus;
use BackedEnum;
use InvalidArgumentException;

/**
 * Maps each status enum to one badge tone from DESIGN.md.
 */
final class StatusTone
{
    public const string SUCCESS = 'success';

    public const string WARNING = 'warning';

    public const string DANGER = 'danger';

    public const string INFO = 'info';

    public const string NEUTRAL = 'neutral';

    /**
     * Tone name for a status enum case.
     */
    public static function for(BackedEnum $status): string
    {
        return match (true) {
            $status instanceof InvoiceStatus => self::invoice($status),
            $status instanceof PaymentStatus => self::payment($status),
            $status instanceof RegistrationStatus => self::registration($status),
            $status instanceof ResultStatus => self::result($status),
            $status instanceof StudentStatus => self::student($status),
            $status instanceof ApplicantStatus => self::applicant($status),
            $status instanceof EmailStatus => self::email($status),
            $status instanceof AttendanceStatus => self::attendance($status),
            default => throw new InvalidArgumentException('This status has no badge colour.'),
        };
    }

    /**
     * Tailwind token classes for a tone. The badge text is still required.
     */
    public static function classes(string $tone): string
    {
        return match ($tone) {
            self::SUCCESS => 'bg-success-bg text-success-text',
            self::WARNING => 'bg-warning-bg text-warning-text',
            self::DANGER => 'bg-danger-bg text-danger-text',
            self::INFO => 'bg-info-bg text-info-text',
            self::NEUTRAL => 'bg-neutral-bg text-neutral-text',
            default => throw new InvalidArgumentException('Unknown badge tone.'),
        };
    }

    /**
     * Lucide-style icon name that accompanies the tone.
     */
    public static function icon(string $tone): string
    {
        return match ($tone) {
            self::SUCCESS => 'check',
            self::WARNING => 'alert',
            self::DANGER => 'x',
            self::INFO => 'info',
            self::NEUTRAL => 'minus',
            default => throw new InvalidArgumentException('Unknown badge tone.'),
        };
    }

    /**
     * Invoice badge tones.
     */
    private static function invoice(InvoiceStatus $status): string
    {
        return match ($status) {
            InvoiceStatus::Unpaid => self::WARNING,
            InvoiceStatus::PartPaid => self::INFO,
            InvoiceStatus::Paid => self::SUCCESS,
            InvoiceStatus::Cancelled => self::NEUTRAL,
        };
    }

    /**
     * Payment badge tones. Expired is neutral.
     */
    private static function payment(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Pending => self::WARNING,
            PaymentStatus::Successful => self::SUCCESS,
            PaymentStatus::Failed => self::DANGER,
            PaymentStatus::Cancelled => self::NEUTRAL,
            PaymentStatus::Expired => self::NEUTRAL,
            PaymentStatus::Reversed => self::DANGER,
        };
    }

    /**
     * Course registration badge tones.
     */
    private static function registration(RegistrationStatus $status): string
    {
        return match ($status) {
            RegistrationStatus::Draft => self::NEUTRAL,
            RegistrationStatus::Submitted => self::INFO,
            RegistrationStatus::Approved => self::SUCCESS,
            RegistrationStatus::Rejected => self::DANGER,
        };
    }

    /**
     * Result badge tones.
     */
    private static function result(ResultStatus $status): string
    {
        return match ($status) {
            ResultStatus::Draft => self::NEUTRAL,
            ResultStatus::Submitted => self::INFO,
            ResultStatus::Approved => self::WARNING,
            ResultStatus::Published => self::SUCCESS,
        };
    }

    /**
     * Student record badge tones.
     */
    private static function student(StudentStatus $status): string
    {
        return match ($status) {
            StudentStatus::Active => self::SUCCESS,
            StudentStatus::Suspended => self::DANGER,
            StudentStatus::Deferred => self::WARNING,
            StudentStatus::Graduated => self::INFO,
            StudentStatus::Withdrawn => self::NEUTRAL,
        };
    }

    /**
     * Applicant badge tones.
     */
    private static function applicant(ApplicantStatus $status): string
    {
        return match ($status) {
            ApplicantStatus::Applied => self::NEUTRAL,
            ApplicantStatus::UnderReview => self::INFO,
            ApplicantStatus::Admitted => self::SUCCESS,
            ApplicantStatus::Rejected => self::DANGER,
            ApplicantStatus::Converted => self::SUCCESS,
        };
    }

    /**
     * Email outbox badge tones.
     */
    private static function email(EmailStatus $status): string
    {
        return match ($status) {
            EmailStatus::Queued => self::WARNING,
            EmailStatus::Sent => self::SUCCESS,
            EmailStatus::Failed => self::DANGER,
        };
    }

    /**
     * Attendance badge tones.
     */
    private static function attendance(AttendanceStatus $status): string
    {
        return match ($status) {
            AttendanceStatus::Present => self::SUCCESS,
            AttendanceStatus::Absent => self::DANGER,
            AttendanceStatus::Late => self::WARNING,
            AttendanceStatus::Excused => self::INFO,
        };
    }
}
