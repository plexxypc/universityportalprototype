<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Departments follow academic_structure.
 *
 * A Faculty Admin may create a department in their faculty. A Department
 * Officer may update their own department and may not create one. The
 * service checks the faculty id on every write.
 */
final class DepartmentPolicy extends PortalPolicy
{
    /**
     * Permission key required to list or open a department.
     */
    protected function viewAbility(): string
    {
        return 'academic_structure.view';
    }

    /**
     * Permission key required to change a department.
     */
    protected function manageAbility(): string
    {
        return 'academic_structure.manage';
    }

    /**
     * Whether the account may create a department.
     */
    public function create(User $user): bool
    {
        if (! parent::create($user)) {
            return false;
        }

        return $user->hasRole(Role::SuperAdmin)
            || $user->hasRole(Role::Registrar)
            || $user->hasRole(Role::FacultyAdmin);
    }
}
