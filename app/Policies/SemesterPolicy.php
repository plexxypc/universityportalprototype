<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Semesters. View follows academic_structure.
 *
 * Writes stay with Super Admin and Registrar.
 */
final class SemesterPolicy extends PortalPolicy
{
    /**
     * Permission key required to list or open a semester.
     */
    protected function viewAbility(): string
    {
        return 'academic_structure.view';
    }

    /**
     * Permission key required to change a semester.
     */
    protected function manageAbility(): string
    {
        return 'academic_structure.manage';
    }

    /**
     * Whether the account may create a semester.
     */
    public function create(User $user): bool
    {
        return parent::create($user) && $this->institutionWide($user);
    }

    /**
     * Whether the account may update this semester.
     */
    public function update(User $user, Model $record): bool
    {
        return parent::update($user, $record) && $this->institutionWide($user);
    }

    /**
     * Super Admin and Registrar act for the whole institution.
     */
    private function institutionWide(User $user): bool
    {
        return $user->hasRole(Role::SuperAdmin) || $user->hasRole(Role::Registrar);
    }
}
