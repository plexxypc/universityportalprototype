<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Receipts. The row is reached through its payment. Download uses view.
 */
final class ReceiptPolicy extends PortalPolicy
{
    /**
     * Permission key required to list or open a receipt.
     */
    protected function viewAbility(): string
    {
        return 'fees.view';
    }

    /**
     * Permission key required to change a receipt.
     */
    protected function manageAbility(): string
    {
        return 'fees.manage';
    }
}
