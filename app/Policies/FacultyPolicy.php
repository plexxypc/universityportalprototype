<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Faculties. A lecturer sees only faculties that own an assigned course.
 * That is narrower than the unscoped academic_structure view cell.
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
}
