<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * In-app notification types from ADR-029, ADR-031, and ADR-036.
 *
 * Credentials and password reset are email only and are not listed here.
 * A registration rejection notice does not carry the reason.
 * A returned result notice does. It is not emailed.
 */
enum NotificationType: string
{
    case Admission = 'admission';
    case RegistrationSubmitted = 'registration_submitted';
    case RegistrationConfirmed = 'registration_confirmed';
    case RegistrationRejected = 'registration_rejected';
    case PaymentConfirmed = 'payment_confirmed';
    case ResultPublished = 'result_published';
    case ResultReturned = 'result_returned';
    case Announcement = 'announcement';
    case TimetablePublished = 'timetable_published';
}
