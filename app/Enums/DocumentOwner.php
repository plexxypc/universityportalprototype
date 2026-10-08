<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Document owner labels: Applicant, Student.
 *
 * The stored text is this label. It is not a class name. There is no foreign key.
 */
enum DocumentOwner: string
{
    case Applicant = 'Applicant';
    case Student = 'Student';
}
