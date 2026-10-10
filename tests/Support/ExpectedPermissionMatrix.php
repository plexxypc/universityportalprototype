<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\PermissionAccess;
use App\Enums\PermissionScope;
use App\Enums\Role;
use InvalidArgumentException;

/**
 * The PRD section 5 matrix, written independently of the permission map.
 */
final class ExpectedPermissionMatrix
{
    /**
     * Every matrix cell, including none.
     *
     * @return array<string, array<string, array{0: PermissionAccess, 1: list<PermissionScope>}>>
     */
    public static function cells(): array
    {
        return [
            'users_roles_settings' => self::row([
                Role::SuperAdmin->value => self::manage(),
            ]),
            'grading_configuration' => self::row([
                Role::SuperAdmin->value => self::manage(),
                Role::Registrar->value => self::view(),
                Role::ExamOfficer->value => self::view(),
            ]),
            'academic_structure' => self::row([
                Role::SuperAdmin->value => self::manage(),
                Role::Registrar->value => self::manage(),
                Role::Bursar->value => self::view(),
                Role::FacultyAdmin->value => self::manage(PermissionScope::Scoped),
                Role::DepartmentOfficer->value => self::manage(PermissionScope::Scoped),
                Role::Lecturer->value => self::view(),
                Role::ExamOfficer->value => self::view(),
            ]),
            'student_records' => self::row([
                Role::SuperAdmin->value => self::manage(),
                Role::Registrar->value => self::manage(),
                Role::Bursar->value => self::view(),
                Role::FacultyAdmin->value => self::view(PermissionScope::Scoped),
                Role::DepartmentOfficer->value => self::view(PermissionScope::Scoped),
                Role::Lecturer->value => self::view(PermissionScope::OwnCourses),
                Role::ExamOfficer->value => self::view(),
                Role::Student->value => self::view(PermissionScope::Own),
            ]),
            'course_registration_submit' => self::row([
                Role::Student->value => self::manage(PermissionScope::Own),
            ]),
            'course_registration_approve' => self::row([
                Role::SuperAdmin->value => self::manage(),
                Role::Registrar->value => self::manage(),
                Role::FacultyAdmin->value => self::manage(PermissionScope::Scoped),
                Role::DepartmentOfficer->value => self::manage(PermissionScope::Scoped),
            ]),
            'fees' => self::row([
                Role::SuperAdmin->value => self::manage(),
                Role::Registrar->value => self::view(),
                Role::Bursar->value => self::manage(),
                Role::Student->value => self::view(PermissionScope::Own),
            ]),
            'payments' => self::row([
                Role::Student->value => self::manage(PermissionScope::Own),
            ]),
            'results_enter' => self::row([
                Role::SuperAdmin->value => self::manage(),
                Role::Lecturer->value => self::manage(PermissionScope::OwnCourses),
            ]),
            'results_approve' => self::row([
                Role::SuperAdmin->value => self::manage(),
                Role::FacultyAdmin->value => self::view(),
                Role::DepartmentOfficer->value => self::manage(PermissionScope::Scoped),
                Role::ExamOfficer->value => self::manage(),
            ]),
            'results_publish' => self::row([
                Role::SuperAdmin->value => self::manage(),
                Role::ExamOfficer->value => self::manage(),
            ]),
            'results_view' => self::row([
                Role::SuperAdmin->value => self::manage(),
                Role::Registrar->value => self::view(),
                Role::FacultyAdmin->value => self::view(PermissionScope::Scoped),
                Role::DepartmentOfficer->value => self::view(PermissionScope::Scoped),
                Role::Lecturer->value => self::view(PermissionScope::OwnCourses),
                Role::ExamOfficer->value => self::view(),
                Role::Student->value => self::view(PermissionScope::Own, PermissionScope::PublishedOnly),
            ]),
            'attendance' => self::row([
                Role::SuperAdmin->value => self::manage(),
                Role::Registrar->value => self::view(),
                Role::FacultyAdmin->value => self::view(PermissionScope::Scoped),
                Role::DepartmentOfficer->value => self::view(PermissionScope::Scoped),
                Role::Lecturer->value => self::manage(PermissionScope::OwnCourses),
                Role::Student->value => self::view(PermissionScope::Own),
            ]),
            'exam_timetable' => self::row([
                Role::SuperAdmin->value => self::manage(),
                Role::Registrar->value => self::view(),
                Role::FacultyAdmin->value => self::view(),
                Role::DepartmentOfficer->value => self::view(),
                Role::Lecturer->value => self::view(),
                Role::ExamOfficer->value => self::manage(),
                Role::Student->value => self::view(),
            ]),
            'announcements' => self::row([
                Role::SuperAdmin->value => self::manage(),
                Role::Registrar->value => self::manage(),
                Role::Bursar->value => self::manage(PermissionScope::Finance),
                Role::FacultyAdmin->value => self::manage(PermissionScope::Scoped),
                Role::DepartmentOfficer->value => self::manage(PermissionScope::Scoped),
                Role::ExamOfficer->value => self::view(),
                Role::Student->value => self::view(),
            ]),
            'reports' => self::row([
                Role::SuperAdmin->value => self::manage(),
                Role::Registrar->value => self::manage(),
                Role::Bursar->value => self::manage(PermissionScope::Finance),
                Role::FacultyAdmin->value => self::view(PermissionScope::Scoped),
                Role::DepartmentOfficer->value => self::view(PermissionScope::Scoped),
                Role::Lecturer->value => self::view(PermissionScope::Own),
                Role::ExamOfficer->value => self::view(PermissionScope::Results),
            ]),
            'audit_logs' => self::row([
                Role::SuperAdmin->value => self::manage(),
            ]),
        ];
    }

    /**
     * Permission keys in the same order as the map.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        $keys = [];

        foreach (self::abilities() as $abilities) {
            foreach ($abilities as $ability) {
                $keys[] = $ability;
            }
        }

        return $keys;
    }

    /**
     * Whether the matrix grants this ability to this one role.
     *
     * A view key is granted at view or manage. An action key is granted only
     * at manage, so the Faculty Admin view cell on results approval grants nothing.
     */
    public static function grants(Role $role, string $ability): bool
    {
        $row = self::rowFor($ability);
        $access = self::cells()[$row][$role->value][0];

        if (str_ends_with($ability, '.view')) {
            return $access === PermissionAccess::Manage || $access === PermissionAccess::View;
        }

        return $access === PermissionAccess::Manage;
    }

    /**
     * Ability keys grouped by matrix row.
     *
     * @return array<string, list<string>>
     */
    private static function abilities(): array
    {
        return [
            'users_roles_settings' => ['users_roles_settings.manage'],
            'grading_configuration' => ['grading_configuration.view', 'grading_configuration.manage'],
            'academic_structure' => ['academic_structure.view', 'academic_structure.manage'],
            'student_records' => ['student_records.view', 'student_records.manage'],
            'course_registration_submit' => ['course_registration.submit'],
            'course_registration_approve' => ['course_registration.approve'],
            'fees' => ['fees.view', 'fees.manage'],
            'payments' => ['payments.make'],
            'results_enter' => ['results.enter'],
            'results_approve' => ['results.approve'],
            'results_publish' => ['results.publish'],
            'results_view' => ['results.view'],
            'attendance' => ['attendance.view', 'attendance.manage'],
            'exam_timetable' => ['exam_timetable.view', 'exam_timetable.manage'],
            'announcements' => ['announcements.view', 'announcements.manage'],
            'reports' => ['reports.view', 'reports.manage'],
            'audit_logs' => ['audit_logs.view'],
        ];
    }

    /**
     * Fill every role. A role omitted from the grants is none, with no scope.
     *
     * @param  array<string, array{0: PermissionAccess, 1: list<PermissionScope>}>  $grants
     * @return array<string, array{0: PermissionAccess, 1: list<PermissionScope>}>
     */
    private static function row(array $grants): array
    {
        $row = [];

        foreach (Role::cases() as $role) {
            $row[$role->value] = $grants[$role->value] ?? [PermissionAccess::None, []];
        }

        return $row;
    }

    /**
     * A manage cell, with the scope markers the matrix writes for it.
     *
     * @return array{0: PermissionAccess, 1: list<PermissionScope>}
     */
    private static function manage(PermissionScope ...$scopes): array
    {
        return [PermissionAccess::Manage, array_values($scopes)];
    }

    /**
     * A view cell, with the scope markers the matrix writes for it.
     *
     * @return array{0: PermissionAccess, 1: list<PermissionScope>}
     */
    private static function view(PermissionScope ...$scopes): array
    {
        return [PermissionAccess::View, array_values($scopes)];
    }

    /**
     * Matrix row that owns this ability key.
     */
    private static function rowFor(string $ability): string
    {
        foreach (self::abilities() as $row => $abilities) {
            if (in_array($ability, $abilities, true)) {
                return $row;
            }
        }

        throw new InvalidArgumentException('Unknown permission.');
    }
}
