<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which attempt counts when a student repeats a course.
 *
 * Latest uses the newest semester. Best uses the higher grade points.
 * The cap on a resit, when there is one, lives on the grading scheme.
 */
enum RepeatPolicy: string
{
    case Latest = 'Latest';
    case Best = 'Best';
}
