<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Staff finance screens. A student fees.view grant does not open them.
 *
 * Super Admin, Bursar, and Registrar may read. Super Admin and Bursar may change.
 */
final class FinanceAccess
{
    /**
     * Whether this account may open a finance screen.
     */
    public static function canView(User $user): bool
    {
        return self::isFinanceStaff($user) && Gate::forUser($user)->allows('fees.view');
    }

    /**
     * Whether this account may re-verify, reverse, or resolve a review.
     */
    public static function canManage(User $user): bool
    {
        return self::isFinanceStaff($user) && Gate::forUser($user)->allows('fees.manage');
    }

    /**
     * Registrar, Bursar, or Super Admin. A students row is not enough.
     */
    private static function isFinanceStaff(User $user): bool
    {
        return $user->hasRole(Role::SuperAdmin)
            || $user->hasRole(Role::Bursar)
            || $user->hasRole(Role::Registrar);
    }
}
