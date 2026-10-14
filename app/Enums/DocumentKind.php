<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Closed list of student document kinds.
 */
enum DocumentKind: string
{
    case BirthCertificate = 'Birth certificate';
    case OLevel = 'O-Level result';
    case Jamb = 'JAMB result';
    case AdmissionLetter = 'Admission letter';
    case PassportPhotograph = 'Passport photograph';
    case Other = 'Other';
}
