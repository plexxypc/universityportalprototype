<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\CourseRegistration;
use App\Models\User;
use App\Support\Rbac\Permissions;

/**
 * Course registrations.
 *
 * Lecturers are left out of visibleTo() even though student_records would
 * mark their courses. They cannot list or open a registration. Approve
 * uses course_registration.approve. Submit stays on the gate key
 * course_registration.submit, which Super Admin is denied.
 */
final class CourseRegistrationPolicy extends PortalPolicy
{
    /**
     * Permission key required to open a registration.
     */
    protected function viewAbility(): string
    {
        return 'student_records.view';
    }

    /**
     * Lecturers do not receive the student_records list for registrations.
     */
    public function viewAny(User $user): bool
    {
        if ($user->status !== UserStatus::Active) {
            return false;
        }

        foreach ($user->roles() as $role) {
            if ($role === Role::Lecturer) {
                continue;
            }

            if (Permissions::roleGrants($role, 'student_records.view')
                || Permissions::roleGrants($role, 'course_registration.submit')
                || Permissions::roleGrants($role, 'course_registration.approve')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the account may approve this registration.
     */
    public function approve(User $user, CourseRegistration $registration): bool
    {
        return $this->allows($user, 'course_registration.approve')
            && $this->rowIsVisible($user, $registration);
    }
}
