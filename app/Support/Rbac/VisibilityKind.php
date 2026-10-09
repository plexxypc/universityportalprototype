<?php

declare(strict_types=1);

namespace App\Support\Rbac;

/**
 * How a visibleTo() query reaches faculty, department, and course scope.
 */
enum VisibilityKind
{
    case Student;
    case StudentId;
    case Result;
    case Attendance;
    case Receipt;
    case Document;
    case Notification;
    case Faculty;
    case Department;
    case Programme;
    case Course;

    /**
     * Faculty, department, programme, and course rows.
     */
    public function isStructure(): bool
    {
        return match ($this) {
            self::Faculty, self::Department, self::Programme, self::Course => true,
            default => false,
        };
    }
}
