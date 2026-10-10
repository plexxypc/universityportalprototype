<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Courses. A lecturer sees only courses present on course_assignments.
 * That is narrower than the unscoped academic_structure view cell.
 */
final class CoursePolicy extends PortalPolicy
{
    /**
     * Permission key required to list or open a course.
     */
    protected function viewAbility(): string
    {
        return 'academic_structure.view';
    }

    /**
     * Permission key required to change a course.
     */
    protected function manageAbility(): string
    {
        return 'academic_structure.manage';
    }
}
