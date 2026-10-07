<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Student record labels from DESIGN.md: Active, Suspended, Deferred, Graduated, Withdrawn.
 */
enum StudentStatus: string
{
    case Active = 'Active';
    case Suspended = 'Suspended';
    case Deferred = 'Deferred';
    case Graduated = 'Graduated';
    case Withdrawn = 'Withdrawn';
}
