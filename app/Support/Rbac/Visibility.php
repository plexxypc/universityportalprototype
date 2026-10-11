<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Enums\DocumentOwner;
use App\Enums\PermissionAccess;
use App\Enums\PermissionScope;
use App\Enums\RegistrationStatus;
use App\Enums\ResultStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds one visibleTo() query from the permission map.
 *
 * Each granted cell becomes one branch. Branches are OR-ed inside a single
 * WHERE. Faculty, department, and course limits are whereIn or whereExists
 * subqueries, so a list does not run a query per row. An account with no
 * granted branch matches nothing. An empty scope id list matches nothing.
 */
final class Visibility
{
    /**
     * Limit the query to rows this account may see.
     *
     * An inactive account matches nothing. Notifications stay on the signed-in
     * account, including Super Admin. Every other model is unfiltered for an
     * Active Super Admin. Submit and pay stay on the gate keys, not on this query.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, User $user, VisibilityProfile $profile): Builder
    {
        if ($user->status !== UserStatus::Active) {
            return self::matchNone($query);
        }

        if ($profile->kind === VisibilityKind::Notification) {
            return $query->where($query->qualifyColumn('user_id'), $user->id);
        }

        if ($user->hasRole(Role::SuperAdmin)) {
            return $query;
        }

        if ($profile->kind === VisibilityKind::Document) {
            return self::applyDocuments($query, $user);
        }

        return self::applyFromMap($query, $user, $profile);
    }

    /**
     * Documents are narrower than the student_records cell.
     *
     * The owning student, the Registrar, and the Super Admin may see them.
     * Super Admin is handled before this method. Bursar, Exam Officer,
     * Faculty Admin, Department Officer, and Lecturer receive no branch.
     * A student matches a Student owner only, so applicant files stay hidden.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function applyDocuments(Builder $query, User $user): Builder
    {
        $branches = [];

        if ($user->hasRole(Role::Student)) {
            $branches[] = function (Builder $branch) use ($user): void {
                $branch->where($branch->qualifyColumn('owner_type'), DocumentOwner::Student->value)
                    ->whereIn($branch->qualifyColumn('owner_id'), self::studentIdQuery($user));
            };
        }

        if ($user->hasRole(Role::Registrar)) {
            $branches[] = function (Builder $branch): void {
                $branch->whereRaw('1 = 1');
            };
        }

        return self::anyBranch($query, $branches);
    }

    /**
     * OR one branch per granted permission cell.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function applyFromMap(Builder $query, User $user, VisibilityProfile $profile): Builder
    {
        $branches = [];

        foreach ($user->roles() as $role) {
            if (in_array($role, $profile->skipRoles, true) || $role === Role::SuperAdmin) {
                continue;
            }

            if ($role === Role::Lecturer && $profile->kind->isStructure()) {
                if (self::mapGrants($role, $profile)) {
                    $branches[] = function (Builder $branch) use ($user, $profile): void {
                        self::applyLecturerStructure($branch, $user, $profile->kind);
                    };
                }

                continue;
            }

            foreach ($profile->rows as $row) {
                $cell = Permissions::cell($role, $row);

                if ($cell->access === PermissionAccess::None) {
                    continue;
                }

                $branches[] = function (Builder $branch) use ($user, $role, $cell, $profile): void {
                    self::applyCell($branch, $user, $role, $cell, $profile);
                };
            }
        }

        return self::anyBranch($query, $branches);
    }

    /**
     * Whether any configured row grants this role view or manage.
     */
    private static function mapGrants(Role $role, VisibilityProfile $profile): bool
    {
        foreach ($profile->rows as $row) {
            if (Permissions::cell($role, $row)->access !== PermissionAccess::None) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply one cell's scopes to a single branch.
     *
     * Scopes on the same cell are AND-ed. Published-only stays with the
     * student cell. Draft results are then hidden from every role except
     * the lecturer branch, which keeps every status for an assigned course.
     */
    private static function applyCell(
        Builder $branch,
        User $user,
        Role $role,
        PermissionCell $cell,
        VisibilityProfile $profile,
    ): void {
        if ($cell->scopes === []) {
            $branch->whereRaw('1 = 1');
        }

        foreach ($cell->scopes as $scope) {
            match ($scope) {
                PermissionScope::Own => self::applyOwn($branch, $user, $profile->kind),
                PermissionScope::Scoped => self::applyScoped($branch, $user, $role, $profile->kind),
                PermissionScope::OwnCourses => self::applyOwnCourses($branch, $user, $profile->kind),
                PermissionScope::PublishedOnly => self::applyPublished($branch),
                PermissionScope::Finance, PermissionScope::Results => $branch->whereRaw('1 = 1'),
            };
        }

        self::hideDraftResults($branch, $role, $profile->kind);
    }

    /**
     * Faculty Admin, Department Officer, Registrar, and Exam Officer see
     * Submitted, Approved, and Published results. Students are already
     * limited to Published. Lecturers keep drafts for an assigned course.
     */
    private static function hideDraftResults(Builder $branch, Role $role, VisibilityKind $kind): void
    {
        if ($kind !== VisibilityKind::Result || $role === Role::Lecturer || $role === Role::Student) {
            return;
        }

        $branch->whereIn($branch->qualifyColumn('status'), [
            ResultStatus::Submitted->value,
            ResultStatus::Approved->value,
            ResultStatus::Published->value,
        ]);
    }

    /**
     * Own Published rows, including the published_at the database requires.
     */
    private static function applyPublished(Builder $branch): void
    {
        $branch->where($branch->qualifyColumn('status'), ResultStatus::Published->value)
            ->whereNotNull($branch->qualifyColumn('published_at'));
    }

    /**
     * Own rows for the account's student or user.
     */
    private static function applyOwn(Builder $branch, User $user, VisibilityKind $kind): void
    {
        match ($kind) {
            VisibilityKind::Student => $branch->where($branch->qualifyColumn('user_id'), $user->id),
            VisibilityKind::StudentId, VisibilityKind::Result, VisibilityKind::Attendance => $branch->whereIn(
                $branch->qualifyColumn('student_id'),
                self::studentIdQuery($user),
            ),
            VisibilityKind::Receipt => self::applyReceiptOwner($branch, $user),
            default => self::matchNone($branch),
        };
    }

    /**
     * Faculty Admin uses faculty ids. Department Officer uses department ids.
     */
    private static function applyScoped(Builder $branch, User $user, Role $role, VisibilityKind $kind): void
    {
        if ($role === Role::FacultyAdmin) {
            self::applyFaculty($branch, $user->facultyIds(), $kind);

            return;
        }

        if ($role === Role::DepartmentOfficer) {
            self::applyDepartment($branch, $user->departmentIds(), $kind);

            return;
        }

        self::matchNone($branch);
    }

    /**
     * Lecturer rows.
     *
     * Students require an Approved registration item for an assigned course
     * in the assigned semester. Results require an assignment to that course
     * in any semester. Attendance requires the meeting's course and semester.
     */
    private static function applyOwnCourses(Builder $branch, User $user, VisibilityKind $kind): void
    {
        match ($kind) {
            VisibilityKind::Student => self::applyLecturerStudents($branch, $user),
            VisibilityKind::Result => self::applyLecturerResults($branch, $user),
            VisibilityKind::Attendance => self::applyLecturerAttendance($branch, $user),
            default => self::matchNone($branch),
        };
    }

    /**
     * @param  list<int>  $facultyIds
     */
    private static function applyFaculty(Builder $branch, array $facultyIds, VisibilityKind $kind): void
    {
        if ($facultyIds === []) {
            self::matchNone($branch);

            return;
        }

        match ($kind) {
            VisibilityKind::Faculty => self::whereIds($branch, $branch->qualifyColumn('id'), $facultyIds),
            VisibilityKind::Department => self::whereIds($branch, $branch->qualifyColumn('faculty_id'), $facultyIds),
            VisibilityKind::Programme, VisibilityKind::Course => $branch->whereIn(
                $branch->qualifyColumn('department_id'),
                function ($sub) use ($facultyIds): void {
                    $sub->select('id')->from('departments')->whereIn('faculty_id', $facultyIds);
                },
            ),
            VisibilityKind::Student => $branch->whereIn(
                $branch->qualifyColumn('programme_id'),
                function ($sub) use ($facultyIds): void {
                    $sub->select('programmes.id')
                        ->from('programmes')
                        ->join('departments', 'departments.id', '=', 'programmes.department_id')
                        ->whereIn('departments.faculty_id', $facultyIds);
                },
            ),
            VisibilityKind::StudentId, VisibilityKind::Result, VisibilityKind::Attendance => $branch->whereIn(
                $branch->qualifyColumn('student_id'),
                self::studentsInFaculties($facultyIds),
            ),
            VisibilityKind::Receipt => self::applyReceiptStudents($branch, self::studentsInFaculties($facultyIds)),
            default => self::matchNone($branch),
        };
    }

    /**
     * @param  list<int>  $departmentIds
     */
    private static function applyDepartment(Builder $branch, array $departmentIds, VisibilityKind $kind): void
    {
        if ($departmentIds === []) {
            self::matchNone($branch);

            return;
        }

        match ($kind) {
            VisibilityKind::Department => self::whereIds($branch, $branch->qualifyColumn('id'), $departmentIds),
            VisibilityKind::Programme, VisibilityKind::Course => self::whereIds(
                $branch,
                $branch->qualifyColumn('department_id'),
                $departmentIds,
            ),
            VisibilityKind::Faculty => $branch->whereIn(
                $branch->qualifyColumn('id'),
                function ($sub) use ($departmentIds): void {
                    $sub->select('faculty_id')->from('departments')->whereIn('id', $departmentIds);
                },
            ),
            VisibilityKind::Student => $branch->whereIn(
                $branch->qualifyColumn('programme_id'),
                function ($sub) use ($departmentIds): void {
                    $sub->select('id')->from('programmes')->whereIn('department_id', $departmentIds);
                },
            ),
            VisibilityKind::StudentId, VisibilityKind::Result, VisibilityKind::Attendance => $branch->whereIn(
                $branch->qualifyColumn('student_id'),
                self::studentsInDepartments($departmentIds),
            ),
            VisibilityKind::Receipt => self::applyReceiptStudents($branch, self::studentsInDepartments($departmentIds)),
            default => self::matchNone($branch),
        };
    }

    /**
     * Courses the lecturer is assigned, and the faculty, department, and
     * programmes of the departments that own those courses.
     *
     * The academic_structure cell is an unscoped view. This branch replaces
     * that reading for Lecturer.
     */
    private static function applyLecturerStructure(Builder $branch, User $user, VisibilityKind $kind): void
    {
        $assignedDepartments = function ($sub) use ($user): void {
            $sub->select('courses.department_id')
                ->from('courses')
                ->join('course_assignments', 'course_assignments.course_id', '=', 'courses.id')
                ->join('staff', 'staff.id', '=', 'course_assignments.staff_id')
                ->where('staff.user_id', $user->id);
        };

        match ($kind) {
            VisibilityKind::Course => $branch->whereIn(
                $branch->qualifyColumn('id'),
                function ($sub) use ($user): void {
                    $sub->select('course_assignments.course_id')
                        ->from('course_assignments')
                        ->join('staff', 'staff.id', '=', 'course_assignments.staff_id')
                        ->where('staff.user_id', $user->id);
                },
            ),
            VisibilityKind::Department => $branch->whereIn($branch->qualifyColumn('id'), $assignedDepartments),
            VisibilityKind::Programme => $branch->whereIn($branch->qualifyColumn('department_id'), $assignedDepartments),
            VisibilityKind::Faculty => $branch->whereIn(
                $branch->qualifyColumn('id'),
                function ($sub) use ($user): void {
                    $sub->select('departments.faculty_id')
                        ->from('departments')
                        ->whereIn('departments.id', function ($courses) use ($user): void {
                            $courses->select('courses.department_id')
                                ->from('courses')
                                ->join('course_assignments', 'course_assignments.course_id', '=', 'courses.id')
                                ->join('staff', 'staff.id', '=', 'course_assignments.staff_id')
                                ->where('staff.user_id', $user->id);
                        });
                },
            ),
            default => self::matchNone($branch),
        };
    }

    /**
     * Students with an Approved item on an assigned course and semester.
     *
     * Draft, Submitted, and Rejected registrations do not count.
     */
    private static function applyLecturerStudents(Builder $branch, User $user): void
    {
        $table = $branch->getModel()->getTable();

        $branch->whereExists(function ($sub) use ($user, $table): void {
            $sub->selectRaw('1')
                ->from('course_registration_items')
                ->join(
                    'course_registrations',
                    'course_registrations.id',
                    '=',
                    'course_registration_items.course_registration_id',
                )
                ->join('course_assignments', function ($join): void {
                    $join->on('course_assignments.course_id', '=', 'course_registration_items.course_id')
                        ->on('course_assignments.semester_id', '=', 'course_registrations.semester_id');
                })
                ->join('staff', 'staff.id', '=', 'course_assignments.staff_id')
                ->whereColumn('course_registrations.student_id', $table.'.id')
                ->where('course_registrations.status', RegistrationStatus::Approved->value)
                ->where('staff.user_id', $user->id);
        });
    }

    /**
     * Results for a course the lecturer is assigned, in any semester.
     */
    private static function applyLecturerResults(Builder $branch, User $user): void
    {
        $table = $branch->getModel()->getTable();

        $branch->whereExists(function ($sub) use ($user, $table): void {
            $sub->selectRaw('1')
                ->from('course_assignments')
                ->join('staff', 'staff.id', '=', 'course_assignments.staff_id')
                ->whereColumn('course_assignments.course_id', $table.'.course_id')
                ->where('staff.user_id', $user->id);
        });
    }

    /**
     * Attendance for a meeting whose course and semester are assigned.
     */
    private static function applyLecturerAttendance(Builder $branch, User $user): void
    {
        $table = $branch->getModel()->getTable();

        $branch->whereExists(function ($sub) use ($user, $table): void {
            $sub->selectRaw('1')
                ->from('attendance_sessions')
                ->join('course_assignments', function ($join): void {
                    $join->on('course_assignments.course_id', '=', 'attendance_sessions.course_id')
                        ->on('course_assignments.semester_id', '=', 'attendance_sessions.semester_id');
                })
                ->join('staff', 'staff.id', '=', 'course_assignments.staff_id')
                ->whereColumn('attendance_sessions.id', $table.'.attendance_session_id')
                ->where('staff.user_id', $user->id);
        });
    }

    /**
     * Receipts for the account's own student.
     */
    private static function applyReceiptOwner(Builder $branch, User $user): void
    {
        self::applyReceiptStudents($branch, self::studentIdQuery($user));
    }

    /**
     * Receipts whose payment student is in the subquery.
     */
    private static function applyReceiptStudents(Builder $branch, Closure $students): void
    {
        $table = $branch->getModel()->getTable();

        $branch->whereExists(function ($sub) use ($students, $table): void {
            $sub->selectRaw('1')
                ->from('payments')
                ->whereColumn('payments.id', $table.'.payment_id')
                ->whereIn('payments.student_id', $students);
        });
    }

    /**
     * Subquery of the account's student id. No row matches when they have none.
     */
    private static function studentIdQuery(User $user): Closure
    {
        return function ($sub) use ($user): void {
            $sub->select('id')->from('students')->where('user_id', $user->id);
        };
    }

    /**
     * Students whose programme sits in one of these faculties.
     *
     * @param  list<int>  $facultyIds
     */
    private static function studentsInFaculties(array $facultyIds): Closure
    {
        return function ($sub) use ($facultyIds): void {
            $sub->select('students.id')
                ->from('students')
                ->join('programmes', 'programmes.id', '=', 'students.programme_id')
                ->join('departments', 'departments.id', '=', 'programmes.department_id')
                ->whereIn('departments.faculty_id', $facultyIds);
        };
    }

    /**
     * Students whose programme sits in one of these departments.
     *
     * @param  list<int>  $departmentIds
     */
    private static function studentsInDepartments(array $departmentIds): Closure
    {
        return function ($sub) use ($departmentIds): void {
            $sub->select('students.id')
                ->from('students')
                ->join('programmes', 'programmes.id', '=', 'students.programme_id')
                ->whereIn('programmes.department_id', $departmentIds);
        };
    }

    /**
     * @param  list<int>  $ids
     */
    private static function whereIds(Builder $branch, string $column, array $ids): void
    {
        if ($ids === []) {
            self::matchNone($branch);

            return;
        }

        $branch->whereIn($column, $ids);
    }

    /**
     * OR the branches. No branch means the query matches nothing.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<Closure(Builder<TModel>): void>  $branches
     * @return Builder<TModel>
     */
    private static function anyBranch(Builder $query, array $branches): Builder
    {
        if ($branches === []) {
            return self::matchNone($query);
        }

        return $query->where(function (Builder $outer) use ($branches): void {
            foreach ($branches as $index => $apply) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $outer->{$method}(function (Builder $branch) use ($apply): void {
                    $apply($branch);
                });
            }
        });
    }

    /**
     * Match no rows. An empty scope must not fall through to the full table.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function matchNone(Builder $query): Builder
    {
        return $query->whereRaw('0 = 1');
    }
}
