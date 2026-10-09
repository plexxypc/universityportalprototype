<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Account labels for a user: Active, Suspended, Deactivated.
 */
enum UserStatus: string
{
    case Active = 'Active';
    case Suspended = 'Suspended';
    case Deactivated = 'Deactivated';
}
