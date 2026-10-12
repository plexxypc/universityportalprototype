<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\Faculty;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Faculties follow academic_structure.
 *
 * Creating a faculty is institution-wide. A Faculty Admin may update only
 * their own faculty. A Department Officer may view the parent faculty and
 * may not change it.
 */
final class FacultyPolicy extends PortalPolicy
{
    /**
     * Permission key required to list or open a faculty.
     */
    protected function viewAbility(): string
    {
        return 'academic_structure.view';
    }

    /**
     * Permission key required to change a faculty.
     */
    protected function manageAbility(): string
    {
        return 'academic_structure.manage';
    }

    /**
     * Whether the account may create a faculty.
     */
    public function create(User $user): bool
    {
        return parent::create($user) && $this->institutionWide($user);
    }

    /**
     * Whether the account may update this faculty.
     */
    public function update(User $user, Model $record): bool
    {
        if (! $record instanceof Faculty || ! parent::update($user, $record)) {
            return false;
        }

        if ($this->institutionWide($user)) {
            return true;
        }

        return $user->hasRole(Role::FacultyAdmin)
            && in_array((int) $record->getKey(), $user->facultyIds(), true);
    }

    /**
     * Super Admin and Registrar act for the whole institution.
     */
    private function institutionWide(User $user): bool
    {
        return $user->hasRole(Role::SuperAdmin) || $user->hasRole(Role::Registrar);
    }
}
