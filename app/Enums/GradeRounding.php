<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a hundredths value is rounded.
 *
 * None leaves the course total unchanged before the letter lookup.
 * HalfUp is used when a division becomes a CGPA. A later mode can be added
 * as another case without changing the methods that omit this argument.
 */
enum GradeRounding: string
{
    case None = 'none';
    case HalfUp = 'half_up';
}
