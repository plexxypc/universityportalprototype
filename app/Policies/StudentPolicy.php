<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserStatus;
use App\Models\Student;
use App\Models\User;
use App\Support\CurrentStudent;

/**
 * Student records. Lecturers see students with an approved registration
 * item on an assigned course and semester, through visibleTo().
 */
final class StudentPolicy extends PortalPolicy
{
    /**
     * Permission key required to list or open a student.
     */
    protected function viewAbility(): string
    {
        return 'student_records.view';
    }

    /**
     * Permission key required to change a student.
     */
    protected function manageAbility(): string
    {
        return 'student_records.manage';
    }

    /**
     * Whether this account may change its own phone, address, and guardian contact.
     *
     * There is no extra permission key. student_records.manage would also allow
     * the staff fields. The row must be the authenticated user's student.
     */
    public function updateOwnContact(User $user, Student $student): bool
    {
        if ($user->status !== UserStatus::Active) {
            return false;
        }

        $own = CurrentStudent::find();

        return $own instanceof Student
            && (int) $own->getKey() === (int) $student->getKey()
            && (int) $own->user_id === (int) $user->id;
    }
}
