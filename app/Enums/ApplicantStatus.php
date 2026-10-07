<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Applicant labels from PRD section 6.6 and DESIGN.md.
 *
 * The backed value "Under review" is the DESIGN.md badge label. PRD prose calls the same status Under Review.
 */
enum ApplicantStatus: string
{
    case Applied = 'Applied';
    case UnderReview = 'Under review';
    case Admitted = 'Admitted';
    case Rejected = 'Rejected';
    case Converted = 'Converted';
}
