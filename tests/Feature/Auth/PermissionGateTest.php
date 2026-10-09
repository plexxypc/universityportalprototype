<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Support\Rbac\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\ExpectedPermissionMatrix;

uses(RefreshDatabase::class);

/**
 * An active user holding exactly this role.
 *
 * Student comes from a students row. Staff roles come from role assignments.
 */
function permission_user_for_role(Role $role): User
{
    if ($role === Role::Student) {
        $student = Student::factory()->create();

        return User::query()->findOrFail($student->user_id);
    }

    $user = User::factory()->create();

    if ($role === Role::FacultyAdmin) {
        RoleAssignment::factory()->facultyAdmin()->create([
            'user_id' => $user->id,
        ]);

        return $user;
    }

    if ($role === Role::DepartmentOfficer) {
        RoleAssignment::factory()->departmentOfficer()->create([
            'user_id' => $user->id,
        ]);

        return $user;
    }

    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    return $user;
}

it('registers a gate for every permission key', function () {
    expect(Permissions::keys())->toBe(ExpectedPermissionMatrix::keys());

    foreach (ExpectedPermissionMatrix::keys() as $ability) {
        expect(Gate::has($ability))->toBeTrue();
    }
});

it('allows each matrix permission through the gate and denies the rest', function (Role $role) {
    $user = permission_user_for_role($role);
    $mismatches = [];

    foreach (ExpectedPermissionMatrix::keys() as $ability) {
        $actual = Gate::forUser($user)->allows($ability);
        $expected = ExpectedPermissionMatrix::grants($role, $ability);

        if ($actual !== $expected) {
            $mismatches[] = $ability;
        }
    }

    expect($mismatches)->toBe([]);
})->with([
    'super admin' => [Role::SuperAdmin],
    'registrar' => [Role::Registrar],
    'bursar' => [Role::Bursar],
    'faculty admin' => [Role::FacultyAdmin],
    'department officer' => [Role::DepartmentOfficer],
    'lecturer' => [Role::Lecturer],
    'exam officer' => [Role::ExamOfficer],
    'student' => [Role::Student],
]);

it('allows an active super admin an ability outside the matrix and denies the explicit ones', function () {
    $user = permission_user_for_role(Role::SuperAdmin);

    expect(Gate::forUser($user)->allows('outside.the.matrix'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('course_registration.submit'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('payments.make'))->toBeFalse();
});

it('denies a super admin the two explicit abilities even with a students row', function () {
    $user = User::factory()->create();
    Student::factory()->create(['user_id' => $user->id]);
    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => Role::SuperAdmin,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    expect(Gate::forUser($user)->allows('course_registration.submit'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('payments.make'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('results.view'))->toBeTrue();
});

it('grants a permission when any role on the user grants it', function () {
    $user = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => Role::Registrar,
        'faculty_id' => null,
        'department_id' => null,
    ]);
    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => Role::Bursar,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    expect(Gate::forUser($user)->allows('student_records.manage'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('fees.manage'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('users_roles_settings.manage'))->toBeFalse();
});

it('allows a deactivated super admin nothing', function () {
    $user = User::factory()->deactivated()->create();
    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => Role::SuperAdmin,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    $allowed = array_values(array_filter(
        Permissions::keys(),
        fn (string $ability): bool => Gate::forUser($user)->allows($ability),
    ));

    expect($user->status)->toBe(UserStatus::Deactivated)
        ->and($allowed)->toBe([])
        ->and(Gate::forUser($user)->allows('outside.the.matrix'))->toBeFalse();
});

it('allows a suspended student nothing', function () {
    $user = User::factory()->suspended()->create();
    Student::factory()->create(['user_id' => $user->id]);

    $allowed = array_values(array_filter(
        Permissions::keys(),
        fn (string $ability): bool => Gate::forUser($user)->allows($ability),
    ));

    expect($user->status)->toBe(UserStatus::Suspended)
        ->and($user->hasRole(Role::Student))->toBeTrue()
        ->and($allowed)->toBe([]);
});

it('leaves other gates to decide when the user is not active', function () {
    $user = User::factory()->deactivated()->create();

    Gate::define('inactive_probe', fn (User $account): bool => $account->is($user));

    expect(Gate::forUser($user)->allows('inactive_probe'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('audit_logs.view'))->toBeFalse();
});

it('allows a user with no role nothing', function () {
    $user = User::factory()->create();

    $allowed = array_values(array_filter(
        Permissions::keys(),
        fn (string $ability): bool => Gate::forUser($user)->allows($ability),
    ));

    expect($user->roles())->toBe([])
        ->and($allowed)->toBe([]);
});

it('does not grant permissions from a student role assignment', function () {
    $user = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => Role::Student,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    expect($user->hasRole(Role::Student))->toBeFalse()
        ->and(Gate::forUser($user)->allows('course_registration.submit'))->toBeFalse();
});

it('adds the student role from the students row', function () {
    $user = User::factory()->create();
    Student::factory()->create(['user_id' => $user->id]);
    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => Role::Lecturer,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    expect($user->roles())->toBe([Role::Lecturer, Role::Student])
        ->and($user->hasRole(Role::Student))->toBeTrue()
        ->and(Gate::forUser($user)->allows('payments.make'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('results.enter'))->toBeTrue();
});

it('returns stored scope ids and does not query again on the same user', function () {
    $user = User::factory()->create();
    $faculty = Faculty::factory()->create();
    $department = Department::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => Role::FacultyAdmin,
        'faculty_id' => $faculty->id,
        'department_id' => null,
    ]);
    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => Role::DepartmentOfficer,
        'faculty_id' => null,
        'department_id' => $department->id,
    ]);

    DB::enableQueryLog();
    DB::flushQueryLog();

    expect($user->hasRole(Role::FacultyAdmin))->toBeTrue();

    $queries = count(DB::getQueryLog());

    expect($user->hasRole(Role::DepartmentOfficer))->toBeTrue()
        ->and($user->hasRole(Role::Student))->toBeFalse()
        ->and($user->facultyIds())->toBe([(int) $faculty->id])
        ->and($user->departmentIds())->toBe([(int) $department->id])
        ->and($user->roles())->toBe([Role::FacultyAdmin, Role::DepartmentOfficer])
        ->and(count(DB::getQueryLog()))->toBe($queries)
        ->and($queries)->toBe(2);
});
