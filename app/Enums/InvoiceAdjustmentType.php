<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Invoice reductions from FIN-5: Discount, Waiver, Scholarship.
 *
 * The amount reduces what the student owes. Penalties are not a type.
 */
enum InvoiceAdjustmentType: string
{
    case Discount = 'Discount';
    case Waiver = 'Waiver';
    case Scholarship = 'Scholarship';
}
