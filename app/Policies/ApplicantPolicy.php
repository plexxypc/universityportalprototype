<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Applicant records use the student onboarding permission cell.
 *
 * Super Admin and Registrar manage. Other roles that can view student
 * records view applicants inside the same scope.
 */
final class ApplicantPolicy extends PortalPolicy
{
    /**
     * Permission key required to list or open an applicant.
     */
    protected function viewAbility(): string
    {
        return 'student_records.view';
    }

    /**
     * Permission key required to add, import, or change an applicant.
     */
    protected function manageAbility(): string
    {
        return 'student_records.manage';
    }
}
