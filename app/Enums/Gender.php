<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Gender labels from PRD section 6.2: Male, Female, Other.
 */
enum Gender: string
{
    case Male = 'Male';
    case Female = 'Female';
    case Other = 'Other';
}
