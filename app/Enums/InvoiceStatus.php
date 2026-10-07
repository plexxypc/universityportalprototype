<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Invoice labels from DESIGN.md: Unpaid, Part-paid, Paid, Cancelled.
 */
enum InvoiceStatus: string
{
    case Unpaid = 'Unpaid';
    case PartPaid = 'Part-paid';
    case Paid = 'Paid';
    case Cancelled = 'Cancelled';
}
