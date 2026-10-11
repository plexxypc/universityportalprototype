<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit rows are read with audit_logs.view. Only Super Admin holds that key.
 *
 * There is no viewer yet. The viewer is TASK-149. Rows are not scoped with
 * visibleTo(); a reader who holds the key sees every row.
 */
final class AuditLogPolicy extends PortalPolicy
{
    /**
     * Permission key required to list or open an audit row.
     */
    protected function viewAbility(): string
    {
        return 'audit_logs.view';
    }

    /**
     * Open a row when the account holds audit_logs.view.
     */
    public function view(User $user, Model $record): bool
    {
        return $record instanceof AuditLog
            && $this->allows($user, $this->viewAbility());
    }
}
