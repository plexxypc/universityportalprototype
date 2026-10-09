<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Enums\PermissionAccess;
use App\Enums\PermissionScope;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Permission map for the PRD section 5 matrix.
 *
 * A gate answers whether the role holds the permission at all. Row-level
 * access is enforced by policies and visibleTo() (Step 2). Do not use these
 * gates alone to authorise access to a single record.
 *
 * Manage implies view. Scope markers stay on the cell. They are not extra
 * permission keys. An action key is granted only when the cell is manage.
 * A view key is granted when the cell is view or manage. With several roles,
 * any role granting the ability is enough.
 */
final class Permissions
{
    /**
     * Abilities Gate::before denies to every Super Admin, including one who also has a students row.
     *
     * @var list<string>
     */
    public const array SUPER_ADMIN_DENIALS = [
        'course_registration.submit',
        'payments.make',
    ];

    /**
     * Matrix cells that are not "none". The first value is manage or view.
     * Further values are scope markers from PRD section 5.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array MATRIX = [
        'users_roles_settings' => [
            'SuperAdmin' => ['manage'],
        ],
        'grading_configuration' => [
            'SuperAdmin' => ['manage'],
            'Registrar' => ['view'],
            'ExamOfficer' => ['view'],
        ],
        'academic_structure' => [
            'SuperAdmin' => ['manage'],
            'Registrar' => ['manage'],
            'Bursar' => ['view'],
            'FacultyAdmin' => ['manage', 'scoped'],
            'DepartmentOfficer' => ['manage', 'scoped'],
            'Lecturer' => ['view'],
            'ExamOfficer' => ['view'],
        ],
        'student_records' => [
            'SuperAdmin' => ['manage'],
            'Registrar' => ['manage'],
            'Bursar' => ['view'],
            'FacultyAdmin' => ['view', 'scoped'],
            'DepartmentOfficer' => ['view', 'scoped'],
            'Lecturer' => ['view', 'own_courses'],
            'ExamOfficer' => ['view'],
            'Student' => ['view', 'own'],
        ],
        'course_registration_submit' => [
            'Student' => ['manage', 'own'],
        ],
        'course_registration_approve' => [
            'SuperAdmin' => ['manage'],
            'Registrar' => ['manage'],
            'FacultyAdmin' => ['manage', 'scoped'],
            'DepartmentOfficer' => ['manage', 'scoped'],
        ],
        'fees' => [
            'SuperAdmin' => ['manage'],
            'Registrar' => ['view'],
            'Bursar' => ['manage'],
            'Student' => ['view', 'own'],
        ],
        'payments' => [
            'Student' => ['manage', 'own'],
        ],
        'results_enter' => [
            'SuperAdmin' => ['manage'],
            'Lecturer' => ['manage', 'own_courses'],
        ],
        'results_approve' => [
            'SuperAdmin' => ['manage'],
            // View only. results.approve is an action key, so this cell grants no ability.
            'FacultyAdmin' => ['view'],
            'DepartmentOfficer' => ['manage', 'scoped'],
            'ExamOfficer' => ['manage'],
        ],
        'results_publish' => [
            'SuperAdmin' => ['manage'],
            'ExamOfficer' => ['manage'],
        ],
        'results_view' => [
            'SuperAdmin' => ['manage'],
            'Registrar' => ['view'],
            'FacultyAdmin' => ['view', 'scoped'],
            'DepartmentOfficer' => ['view', 'scoped'],
            'Lecturer' => ['view', 'own_courses'],
            'ExamOfficer' => ['view'],
            'Student' => ['view', 'own', 'published_only'],
        ],
        'attendance' => [
            'SuperAdmin' => ['manage'],
            'Registrar' => ['view'],
            'FacultyAdmin' => ['view', 'scoped'],
            'DepartmentOfficer' => ['view', 'scoped'],
            'Lecturer' => ['manage', 'own_courses'],
            'Student' => ['view', 'own'],
        ],
        'exam_timetable' => [
            'SuperAdmin' => ['manage'],
            'Registrar' => ['view'],
            'FacultyAdmin' => ['view'],
            'DepartmentOfficer' => ['view'],
            'Lecturer' => ['view'],
            'ExamOfficer' => ['manage'],
            'Student' => ['view'],
        ],
        'announcements' => [
            'SuperAdmin' => ['manage'],
            'Registrar' => ['manage'],
            'Bursar' => ['manage', 'finance'],
            'FacultyAdmin' => ['manage', 'scoped'],
            'DepartmentOfficer' => ['manage', 'scoped'],
            'ExamOfficer' => ['view'],
            'Student' => ['view'],
        ],
        'reports' => [
            'SuperAdmin' => ['manage'],
            'Registrar' => ['manage'],
            'Bursar' => ['manage', 'finance'],
            'FacultyAdmin' => ['view', 'scoped'],
            'DepartmentOfficer' => ['view', 'scoped'],
            'Lecturer' => ['view', 'own'],
            'ExamOfficer' => ['view', 'results'],
        ],
        'audit_logs' => [
            'SuperAdmin' => ['manage'],
        ],
    ];

    /**
     * Permission keys from ADR-028, grouped by matrix row.
     *
     * @var array<string, list<string>>
     */
    private const array ABILITIES = [
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

    /**
     * Register Gate::before and one Gate::define for every permission key.
     *
     * Before returns null when the user is not Active, so it grants nothing
     * and denies nothing. An Active Super Admin is allowed every ability
     * except the explicit denials. Every other check is resolved from the map.
     */
    public static function registerGates(): void
    {
        Gate::before(function (?User $user, string $ability): ?bool {
            if (! $user instanceof User || $user->status !== UserStatus::Active) {
                return null;
            }

            if (! $user->hasRole(Role::SuperAdmin)) {
                return null;
            }

            return ! in_array($ability, self::SUPER_ADMIN_DENIALS, true);
        });

        foreach (self::keys() as $ability) {
            Gate::define($ability, function (User $user) use ($ability): bool {
                if ($user->status !== UserStatus::Active) {
                    return false;
                }

                return self::allows($user, $ability);
            });
        }
    }

    /**
     * Matrix row names, in PRD section 5 order.
     *
     * @return list<string>
     */
    public static function rows(): array
    {
        return array_keys(self::ABILITIES);
    }

    /**
     * Every permission key from ADR-028, in matrix order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        $keys = [];

        foreach (self::ABILITIES as $abilities) {
            foreach ($abilities as $ability) {
                $keys[] = $ability;
            }
        }

        return $keys;
    }

    /**
     * The matrix cell for one role on one row. A missing grant is none.
     */
    public static function cell(Role $role, string $row): PermissionCell
    {
        if (! array_key_exists($row, self::MATRIX)) {
            throw new InvalidArgumentException('Unknown permission row.');
        }

        $grant = self::MATRIX[$row][$role->value] ?? null;

        if ($grant === null) {
            return new PermissionCell(PermissionAccess::None);
        }

        return self::cellFromGrant($grant);
    }

    /**
     * Whether this one role holds the ability.
     *
     * A view key is held at view or manage. An action key, including every
     * .manage key, is held only at manage. The Faculty Admin view cell on
     * results approval therefore does not grant results.approve.
     */
    public static function roleGrants(Role $role, string $ability): bool
    {
        $cell = self::cell($role, self::rowFor($ability));

        if (str_ends_with($ability, '.view')) {
            return $cell->access === PermissionAccess::Manage
                || $cell->access === PermissionAccess::View;
        }

        return $cell->access === PermissionAccess::Manage;
    }

    /**
     * Whether any of the user's roles holds this ability.
     *
     * Super Admin is denied course registration submit and making payments
     * even when another role, including Student, would grant them. This does
     * not check users.status. The gate denies a user who is not Active.
     */
    public static function allows(User $user, string $ability): bool
    {
        if ($user->hasRole(Role::SuperAdmin) && in_array($ability, self::SUPER_ADMIN_DENIALS, true)) {
            return false;
        }

        foreach ($user->roles() as $role) {
            if (self::roleGrants($role, $ability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Matrix row that owns this ability key.
     */
    private static function rowFor(string $ability): string
    {
        foreach (self::ABILITIES as $row => $abilities) {
            if (in_array($ability, $abilities, true)) {
                return $row;
            }
        }

        throw new InvalidArgumentException('Unknown permission.');
    }

    /**
     * Build a cell from one stored grant.
     *
     * @param  list<string>  $grant
     */
    private static function cellFromGrant(array $grant): PermissionCell
    {
        $access = PermissionAccess::tryFrom($grant[0] ?? '');

        if ($access === null || $access === PermissionAccess::None) {
            throw new InvalidArgumentException('A stored permission cell must be manage or view.');
        }

        $scopes = [];

        foreach (array_slice($grant, 1) as $scope) {
            $parsed = PermissionScope::tryFrom($scope);

            if ($parsed === null) {
                throw new InvalidArgumentException('Unknown permission scope.');
            }

            $scopes[] = $parsed;
        }

        return new PermissionCell($access, $scopes);
    }

    /**
     * Staff roles named in the permission map.
     *
     * Student is not a staff role. A role that is missing from the map is
     * not staff either.
     *
     * @return list<Role>
     */
    public static function staffRoles(): array
    {
        $roles = [];

        foreach (self::MATRIX as $grants) {
            foreach (array_keys($grants) as $role_name) {
                if ($role_name === Role::Student->value || isset($roles[$role_name])) {
                    continue;
                }

                $roles[$role_name] = Role::from($role_name);
            }
        }

        return array_values($roles);
    }

    /**
     * Whether this role is a staff role in the permission map.
     */
    public static function isStaffRole(Role $role): bool
    {
        foreach (self::staffRoles() as $staff_role) {
            if ($staff_role === $role) {
                return true;
            }
        }

        return false;
    }
}
