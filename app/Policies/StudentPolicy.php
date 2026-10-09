<?php

declare(strict_types=1);

namespace App\Policies;

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
}
