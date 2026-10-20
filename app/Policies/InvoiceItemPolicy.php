<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Invoice lines. Students see lines on their own invoices.
 */
final class InvoiceItemPolicy extends PortalPolicy
{
    /**
     * Permission key required to list or open a line.
     */
    protected function viewAbility(): string
    {
        return 'fees.view';
    }

    /**
     * Permission key required to change a line.
     */
    protected function manageAbility(): string
    {
        return 'fees.manage';
    }
}
