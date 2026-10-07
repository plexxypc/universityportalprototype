<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The eight roles from PRD section 5. Case names match ARCHITECTURE section 7.
 */
enum Role: string
{
    case SuperAdmin = 'SuperAdmin';
    case Registrar = 'Registrar';
    case Bursar = 'Bursar';
    case FacultyAdmin = 'FacultyAdmin';
    case DepartmentOfficer = 'DepartmentOfficer';
    case Lecturer = 'Lecturer';
    case ExamOfficer = 'ExamOfficer';
    case Student = 'Student';
}
