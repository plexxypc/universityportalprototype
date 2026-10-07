<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Course registration labels from PRD REG-5 and DESIGN.md: Draft, Submitted, Approved, Rejected.
 */
enum RegistrationStatus: string
{
    case Draft = 'Draft';
    case Submitted = 'Submitted';
    case Approved = 'Approved';
    case Rejected = 'Rejected';
}
