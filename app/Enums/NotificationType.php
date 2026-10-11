<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * In-app notification types from ADR-029.
 *
 * Credentials and password reset are email only and are not listed here.
 */
enum NotificationType: string
{
    case Admission = 'admission';
    case RegistrationConfirmed = 'registration_confirmed';
    case PaymentConfirmed = 'payment_confirmed';
    case ResultPublished = 'result_published';
    case Announcement = 'announcement';
    case TimetablePublished = 'timetable_published';
}
