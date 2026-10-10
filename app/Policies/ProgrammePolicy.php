<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Programmes. A lecturer sees programmes in departments that own an assigned course.
 */
final class ProgrammePolicy extends PortalPolicy
{
    /**
     * Permission key required to list or open a programme.
     */
    protected function viewAbility(): string
    {
        return 'academic_structure.view';
    }

    /**
     * Permission key required to change a programme.
     */
    protected function manageAbility(): string
    {
        return 'academic_structure.manage';
    }
}
