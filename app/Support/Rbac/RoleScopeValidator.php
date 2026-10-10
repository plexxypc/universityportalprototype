<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Enums\Role;

/**
 * Check that a role assignment's faculty and department match the role.
 *
 * Super Admin, Registrar, Bursar, Exam Officer, and Lecturer must leave both
 * empty. Faculty Admin requires a faculty and no department. Department
 * Officer requires a department and no faculty. A Student role is rejected.
 * This does not write to the database and does not check that the ids exist.
 */
final class RoleScopeValidator
{
    /**
     * Reasons the assignment is rejected. Empty when it is accepted.
     *
     * @return list<string>
     */
    public function errors(Role $role, ?int $facultyId, ?int $departmentId): array
    {
        if ($role === Role::Student) {
            return ['A student is recorded on the students table, not as a role assignment.'];
        }

        $errors = [];
        $hasFaculty = $facultyId !== null;
        $hasDepartment = $departmentId !== null;

        if ($role === Role::FacultyAdmin) {
            if (! $hasFaculty) {
                $errors[] = 'A Faculty Admin requires a faculty.';
            }

            if ($hasDepartment) {
                $errors[] = 'A Faculty Admin must leave the department empty.';
            }

            return $errors;
        }

        if ($role === Role::DepartmentOfficer) {
            if ($hasFaculty) {
                $errors[] = 'A Department Officer must leave the faculty empty.';
            }

            if (! $hasDepartment) {
                $errors[] = 'A Department Officer requires a department.';
            }

            return $errors;
        }

        if ($hasFaculty) {
            $errors[] = 'This role must leave the faculty empty.';
        }

        if ($hasDepartment) {
            $errors[] = 'This role must leave the department empty.';
        }

        return $errors;
    }

    /**
     * Whether this role may be stored with these scope ids.
     */
    public function accepts(Role $role, ?int $facultyId, ?int $departmentId): bool
    {
        return $this->errors($role, $facultyId, $departmentId) === [];
    }
}
