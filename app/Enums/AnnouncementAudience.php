<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Announcement audience labels from PRD MAIL-5: All, Faculty, Department, Programme, Level.
 *
 * Title case matches the other stored status labels. Level means every student
 * at that level. A combination of these is not a stored audience.
 */
enum AnnouncementAudience: string
{
    case All = 'All';
    case Faculty = 'Faculty';
    case Department = 'Department';
    case Programme = 'Programme';
    case Level = 'Level';
}
