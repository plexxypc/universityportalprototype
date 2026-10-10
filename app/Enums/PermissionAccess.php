<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Access in one cell of the PRD section 5 permission matrix.
 */
enum PermissionAccess: string
{
    case Manage = 'manage';
    case View = 'view';
    case None = 'none';
}
