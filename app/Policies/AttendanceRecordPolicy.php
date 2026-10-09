<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Attendance marks. Lecturers are limited to assigned course meetings.
 */
final class AttendanceRecordPolicy extends PortalPolicy
{
    /**
     * Permission key required to list or open a mark.
     */
    protected function viewAbility(): string
    {
        return 'attendance.view';
    }

    /**
     * Permission key required to change a mark.
     */
    protected function manageAbility(): string
    {
        return 'attendance.manage';
    }
}
