<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * In-app notification types from ADR-029 and ADR-031.
 *
 * Credentials and password reset are email only and are not listed here.
 * A registration rejection notice does not carry the reason.
 */
enum NotificationType: string
{
    case Admission = 'admission';
    case RegistrationSubmitted = 'registration_submitted';
    case RegistrationConfirmed = 'registration_confirmed';
    case RegistrationRejected = 'registration_rejected';
    case PaymentConfirmed = 'payment_confirmed';
    case ResultPublished = 'result_published';
    case Announcement = 'announcement';
    case TimetablePublished = 'timetable_published';
}
