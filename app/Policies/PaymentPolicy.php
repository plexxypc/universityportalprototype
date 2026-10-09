<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Payments. Visibility follows fees and the student's own payments cell.
 * payments.make is the gate key Super Admin is denied. This policy has
 * no make method.
 */
final class PaymentPolicy extends PortalPolicy
{
    /**
     * Permission key required to list or open a payment.
     */
    protected function viewAbility(): string
    {
        return 'fees.view';
    }

    /**
     * Permission key required to change a payment.
     */
    protected function manageAbility(): string
    {
        return 'fees.manage';
    }
}
