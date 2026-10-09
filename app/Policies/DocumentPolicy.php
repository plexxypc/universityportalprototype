<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Documents.
 *
 * Visible only to the owning student, the Registrar, and the Super Admin.
 * Bursar, Exam Officer, Faculty Admin, Department Officer, and Lecturer
 * are denied. This is deliberately narrower than the student_records cell.
 * Applicant-owned files are visible to the Registrar and the Super Admin.
 */
final class DocumentPolicy extends PortalPolicy
{
    /**
     * Unused for view. update still requires student_records.manage.
     */
    protected function viewAbility(): string
    {
        return 'student_records.view';
    }

    /**
     * Permission key required to change a document the account can see.
     */
    protected function manageAbility(): string
    {
        return 'student_records.manage';
    }

    /**
     * The owning student, the Registrar, or the Super Admin may list documents.
     */
    public function viewAny(User $user): bool
    {
        if ($user->status !== UserStatus::Active) {
            return false;
        }

        return $user->hasRole(Role::SuperAdmin)
            || $user->hasRole(Role::Registrar)
            || $user->hasRole(Role::Student);
    }

    /**
     * Open only when visibleTo() contains the row.
     */
    public function view(User $user, Model $record): bool
    {
        return $this->rowIsVisible($user, $record);
    }
}
