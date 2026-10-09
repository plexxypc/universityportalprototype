<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Staff profile labels from PRD STAFF-5: Active, Deactivated.
 */
enum StaffStatus: string
{
    case Active = 'Active';
    case Deactivated = 'Deactivated';
}
