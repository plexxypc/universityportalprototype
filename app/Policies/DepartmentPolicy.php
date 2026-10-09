<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Departments. A lecturer sees only departments that own an assigned course.
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
}
