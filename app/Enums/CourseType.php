<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Programme-course type from PRD SETUP-5 and ACAD-3: Core, Elective.
 */
enum CourseType: string
{
    case Core = 'Core';
    case Elective = 'Elective';
}
