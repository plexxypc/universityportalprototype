<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who an import batch creates: Applicant, Student, or Staff.
 *
 * Staff is the STAFF-2 staff import.
 */
enum ImportTarget: string
{
    case Applicant = 'Applicant';
    case Student = 'Student';
    case Staff = 'Staff';
}
