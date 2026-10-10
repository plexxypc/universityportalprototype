<?php

declare(strict_types=1);

namespace App\Services;

/**
 * What the bootstrap Super Admin command did.
 */
enum SuperAdminBootstrapResult
{
    case Created;
    case Rearmed;
    case Unchanged;
    case EmailTaken;
    case InvalidHash;
    case Rejected;
    case Failed;
}
