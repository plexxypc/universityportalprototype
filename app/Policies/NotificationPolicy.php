<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * In-app notifications. Each account sees its own rows. Super Admin sees all.
 * There is no notifications row on the permission matrix.
 */
final class NotificationPolicy extends PortalPolicy
{
    /**
     * There is no matrix key. view() does not use this ability.
     */
    protected function viewAbility(): string
    {
        return 'audit_logs.view';
    }

    /**
     * Any active account may open the list. visibleTo() keeps it to their rows.
     */
    public function viewAny(User $user): bool
    {
        return $user->status === UserStatus::Active;
    }

    /**
     * Open only when visibleTo() contains the row.
     */
    public function view(User $user, Model $record): bool
    {
        return $this->rowIsVisible($user, $record);
    }
}
