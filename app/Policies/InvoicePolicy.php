<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Invoices. Students see their own. Staff follow the fees cell.
 * Making a payment is payments.make, not this policy.
 */
final class InvoicePolicy extends PortalPolicy
{
    /**
     * Permission key required to list or open an invoice.
     */
    protected function viewAbility(): string
    {
        return 'fees.view';
    }

    /**
     * Permission key required to change an invoice.
     */
    protected function manageAbility(): string
    {
        return 'fees.manage';
    }
}
