<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Attendance marks from PRD ATT-2 and DESIGN.md: Present, Absent, Late, Excused.
 */
enum AttendanceStatus: string
{
    case Present = 'Present';
    case Absent = 'Absent';
    case Late = 'Late';
    case Excused = 'Excused';
}
