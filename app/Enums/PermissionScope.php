<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Scope words written in the PRD section 5 matrix.
 *
 * These stay on the permission cell. They are not extra permission keys.
 */
enum PermissionScope: string
{
    case Own = 'own';
    case Scoped = 'scoped';
    case OwnCourses = 'own_courses';
    case Finance = 'finance';
    case PublishedOnly = 'published_only';
    case Results = 'results';
}
