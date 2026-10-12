<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Academic sessions. View follows academic_structure.
 *
 * Create, update, and delete stay with Super Admin and Registrar.
 * Faculty Admin and Department Officer hold the manage key only inside
 * their own faculty, so they cannot change the calendar.
 */
final class AcademicSessionPolicy extends PortalPolicy
{
    /**
     * Permission key required to list or open a session.
     */
    protected function viewAbility(): string
    {
        return 'academic_structure.view';
    }

    /**
     * Permission key required to change a session.
     */
    protected function manageAbility(): string
    {
        return 'academic_structure.manage';
    }

    /**
     * Whether the account may create a session.
     */
    public function create(User $user): bool
    {
        return parent::create($user) && $this->institutionWide($user);
    }

    /**
     * Whether the account may update this session.
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
