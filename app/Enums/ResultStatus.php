<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Result labels from PRD RES-4 and DESIGN.md: Draft, Submitted, Approved, Published.
 */
enum ResultStatus: string
{
    case Draft = 'Draft';
    case Submitted = 'Submitted';
    case Approved = 'Approved';
    case Published = 'Published';
}
