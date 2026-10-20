<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Invoice adjustments. Students see reductions on their own invoices.
 *
 * Removal is Super Admin only. Bursar may add one through the service.
 */
final class InvoiceAdjustmentPolicy extends PortalPolicy
{
    /**
     * Permission key required to list or open an adjustment.
     */
    protected function viewAbility(): string
    {
        return 'fees.view';
    }

    /**
     * Permission key required to add an adjustment.
     */
    protected function manageAbility(): string
    {
        return 'fees.manage';
    }

    /**
     * Whether the account may remove this adjustment.
     *
     * Super Admin is decided by Gate::before. This method refuses everyone else.
     */
    public function delete(User $user, Model $record): bool
    {
        return $user->hasRole(Role::SuperAdmin) && $this->view($user, $record);
    }
}
