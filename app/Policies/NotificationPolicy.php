<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserStatus;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * In-app notifications. Each account sees and marks only its own rows.
 *
 * Super Admin is not an exception. There is no notifications row on the
 * permission matrix. Gate::before returns null for this model so these
 * methods run.
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
     * Any active account may open its own list. visibleTo() keeps the rows.
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

    /**
     * Nobody creates a notification through the gate.
     *
     * Later phases call NotificationService. The account argument is part of
     * the policy signature and is not granted this ability.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Mark one row read only when it belongs to this account.
     */
    public function markRead(User $user, Notification $notification): bool
    {
        return $user->status === UserStatus::Active
            && $this->rowIsVisible($user, $notification);
    }

    /**
     * Mark this account's own unread rows. The service filters by user_id.
     */
    public function markAllRead(User $user): bool
    {
        return $user->status === UserStatus::Active;
    }
}
