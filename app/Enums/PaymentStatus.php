<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Payment labels from PRD PAY-7: Pending, Successful, Failed, Cancelled, Expired, Reversed.
 */
enum PaymentStatus: string
{
    case Pending = 'Pending';
    case Successful = 'Successful';
    case Failed = 'Failed';
    case Cancelled = 'Cancelled';
    case Expired = 'Expired';
    case Reversed = 'Reversed';
}
