<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\StaffStatus;
use App\Enums\UserStatus;
use App\Models\AcademicSession;
use App\Models\CourseAssignment;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\InstitutionSetting;
use App\Models\Programme;
use App\Models\RoleAssignment;
use App\Models\Semester;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('prevents lazy loading outside production', function () {
    expect(Model::preventsLazyLoading())->toBeTrue();

    Staff::factory()->count(2)->create();
    $staff = Staff::query()->get();

    expect(fn () => $staff->first()?->user)->toThrow(LazyLoadingViolationException::class);
});

it('creates a user and loads its profile relationships', function () {
    $user = User::factory()->create();
    $user->load(['roleAssignments', 'staff', 'student']);

    expect($user->roleAssignments)->toBeEmpty()
        ->and($user->staff)->toBeNull()
        ->and($user->student)->toBeNull()
        ->and(Hash::check('password', (string) $user->password))->toBeFalse()
        ->and(Hash::isHashed((string) $user->password))->toBeTrue();
});

it('creates each user factory state', function () {
    expect(User::factory()->unverified()->create()->email_verified_at)->toBeNull()
        ->and(User::factory()->suspended()->create()->status)->toBe(UserStatus::Suspended)
        ->and(User::factory()->deactivated()->create()->status)->toBe(UserStatus::Deactivated);

    $changing = User::factory()->mustChangePassword()->create();

    expect($changing->must_change_password)->toBeTrue()
        ->and($changing->temp_password_expires_at)->not->toBeNull();
});

it('creates a role assignment and loads its relationships', function () {
    $assignment = RoleAssignment::factory()->create();
    $assignment->load(['user', 'faculty', 'department']);

    expect($assignment->user)->toBeInstanceOf(User::class)
        ->and($assignment->role)->toBe(Role::SuperAdmin)
        ->and($assignment->faculty)->toBeNull()
        ->and($assignment->department)->toBeNull();
});

it('creates each role assignment factory state', function () {
    $faculty_admin = RoleAssignment::factory()->facultyAdmin()->create();
    $faculty_admin->load('faculty');
    $officer = RoleAssignment::factory()->departmentOfficer()->create();
    $officer->load('department');

    expect($faculty_admin->role)->toBe(Role::FacultyAdmin)
        ->and($faculty_admin->faculty)->toBeInstanceOf(Faculty::class)
        ->and($officer->role)->toBe(Role::DepartmentOfficer)
        ->and($officer->department)->toBeInstanceOf(Department::class);
});

it('creates a staff profile and loads its relationships', function () {
    $staff = Staff::factory()->create();
    $staff->load(['user', 'department', 'courseAssignments']);

    expect($staff->user)->toBeInstanceOf(User::class)
        ->and($staff->department)->toBeNull()
        ->and($staff->courseAssignments)->toBeEmpty();
});

it('creates a deactivated staff profile', function () {
    expect(Staff::factory()->deactivated()->create()->status)->toBe(StaffStatus::Deactivated);
});

it('creates a faculty and loads its relationships', function () {
    $faculty = Faculty::factory()->create();
    $faculty->load(['departments', 'announcements', 'roleAssignments']);

    expect($faculty->departments)->toBeEmpty()
        ->and($faculty->announcements)->toBeEmpty()
        ->and($faculty->roleAssignments)->toBeEmpty();
});

it('creates a department and loads its relationships', function () {
    $department = Department::factory()->create();
    $department->load(['faculty', 'programmes', 'courses', 'announcements', 'roleAssignments', 'staff']);

    expect($department->faculty)->toBeInstanceOf(Faculty::class)
        ->and($department->programmes)->toBeEmpty()
        ->and($department->courses)->toBeEmpty()
        ->and($department->announcements)->toBeEmpty()
        ->and($department->roleAssignments)->toBeEmpty()
        ->and($department->staff)->toBeEmpty();
});

it('creates a programme and loads its department', function () {
    $programme = Programme::factory()->create();
    $programme->load('department');

    expect($programme->department)->toBeInstanceOf(Department::class)
        ->and($programme->duration_years)->toBeGreaterThanOrEqual(1);
});

it('creates the institution settings row', function () {
    $settings = InstitutionSetting::factory()->create();

    expect($settings->min_units)->toBeGreaterThanOrEqual(1)
        ->and($settings->max_units)->toBeGreaterThanOrEqual($settings->min_units);
});

it('creates a current academic session and loads its semesters', function () {
    $current = AcademicSession::factory()->current()->create();
    AcademicSession::factory()->create();
    $current->load('semesters');

    expect($current->is_current)->toBeTrue()
        ->and($current->semesters)->toBeEmpty()
        ->and(AcademicSession::query()->current()->pluck('id')->all())->toBe([$current->id]);
});

it('creates an active semester and loads its session', function () {
    $active = Semester::factory()->active()->create();
    Semester::factory()->create();
    $active->load(['academicSession', 'courseAssignments']);

    expect($active->is_active)->toBeTrue()
        ->and($active->academicSession)->toBeInstanceOf(AcademicSession::class)
        ->and($active->courseAssignments)->toBeEmpty()
        ->and(Semester::query()->active()->pluck('id')->all())->toBe([$active->id]);
});

it('creates a course assignment and loads its relationships', function () {
    $assignment = CourseAssignment::factory()->create();
    $assignment->load(['staff', 'course', 'semester']);

    expect($assignment->staff)->toBeInstanceOf(Staff::class)
        ->and($assignment->course)->not->toBeNull()
        ->and($assignment->semester)->toBeInstanceOf(Semester::class);
});
