<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages\Concerns;

use App\Models\User;
use App\Support\Rbac\FinanceAccess;

/**
 * Fees screens are visible to Super Admin, Bursar, and Registrar.
 *
 * Anyone else who opens the address gets the same 404. The navigation
 * item is hidden for them as well.
 */
trait RequiresFeesView
{
    public static function canAccess(): bool
    {
        return static::feesView();
    }

    public function mountCanAuthorizeAccess(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    public function hydrateCanAuthorizeAccess(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    /**
     * Whether the signed-in account may read fees.
     */
    protected static function feesView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && FinanceAccess::canView($user);
    }

    /**
     * Whether the signed-in account may change fees.
     */
    protected static function feesManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User && FinanceAccess::canManage($user);
    }
}
