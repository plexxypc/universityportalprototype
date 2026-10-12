<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Institution profile. Only users_roles_settings.manage may open or change it.
 *
 * That key belongs to Super Admin. There is no separate view key.
 */
final class InstitutionSettingPolicy extends PortalPolicy
{
    /**
     * Permission key required to list or open the profile.
     */
    protected function viewAbility(): string
    {
        return 'users_roles_settings.manage';
    }

    /**
     * Permission key required to change the profile.
     */
    protected function manageAbility(): string
    {
        return 'users_roles_settings.manage';
    }
}
