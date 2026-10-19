<?php

declare(strict_types=1);

use App\Enums\CourseType;
use App\Enums\NotificationType;
use App\Enums\RegistrationStatus;
use App\Enums\StudentStatus;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseRegistration;
use App\Models\EmailOutbox;
use App\Models\InstitutionSetting;
use App\Models\Notification;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\RegistrationService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-18 12:00:00', 'Africa/Lagos'));
    app(SettingsService::class)->forgetCache();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('returns an approved registration to the queue when approval is on', function () {
    $shell = registration_decision_shell();
    $programme = Programme::factory()->create();
    $case = registration_decision_submitted($shell, $programme);
    $elective = Course::factory()->create([
        'department_id' => $programme->department_id,
        'credit_units' => 2,
    ]);
    ProgrammeCourse::factory()->elective()->create([
        'programme_id' => $programme->id,
        'course_id' => $elective->id,
        'level' => 100,
        'semester_no' => 1,
    ]);
    $registrar = registration_decision_registrar();
    app(RegistrationService::class)->approve($registrar, (int) $case['registration']->id, registration_decision_request());
    Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00', 'Africa/Lagos'));
    $changed = app(RegistrationService::class)->addDrop(
        $case['user'],
        [(int) $elective->id],
        registration_decision_request(),
    );

    expect($changed->status)->toBe(RegistrationStatus::Submitted)
        ->and($changed->total_units)->toBe(4)
        ->and($changed->decided_by)->toBeNull()
        ->and(EmailOutbox::query()->count())->toBe(1)
        ->and(Notification::query()->where('type', NotificationType::RegistrationSubmitted)->count())->toBe(2)
        ->and(Notification::query()->where('type', NotificationType::RegistrationConfirmed)->count())->toBe(1);
});

it('keeps an approved registration approved when approval is off', function () {
    $shell = registration_decision_shell();
    $row = InstitutionSetting::query()->first();
    expect($row)->not->toBeNull();
    $row->approval_required = false;
    $row->save();
    app(SettingsService::class)->forgetCache();
    $programme = Programme::factory()->create();
    $student = Student::factory()->create([
        'programme_id' => $programme->id,
        'level' => 100,
        'entry_session_id' => $shell['session']->id,
        'status' => StudentStatus::Active,
    ]);
    $user = $student->user;
    expect($user)->toBeInstanceOf(User::class);
    $core = Course::factory()->create([
        'department_id' => $programme->department_id,
        'credit_units' => 2,
    ]);
    $elective = Course::factory()->create([
        'department_id' => $programme->department_id,
        'credit_units' => 2,
    ]);
    ProgrammeCourse::factory()->create([
        'programme_id' => $programme->id,
        'course_id' => $core->id,
        'level' => 100,
        'semester_no' => 1,
        'type' => CourseType::Core,
    ]);
    ProgrammeCourse::factory()->elective()->create([
        'programme_id' => $programme->id,
        'course_id' => $elective->id,
        'level' => 100,
        'semester_no' => 1,
    ]);
    app(RegistrationService::class)->submit($user, [], registration_decision_request());
    Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00', 'Africa/Lagos'));
    $changed = app(RegistrationService::class)->addDrop(
        $user,
        [(int) $elective->id],
        registration_decision_request(),
    );

    expect($changed->status)->toBe(RegistrationStatus::Approved)
        ->and($changed->total_units)->toBe(4)
        ->and(EmailOutbox::query()->count())->toBe(1)
        ->and(Notification::query()->where('type', NotificationType::RegistrationConfirmed)->count())->toBe(1);
});

it('closes add and drop at the add-drop second', function () {
    $shell = registration_decision_shell();
    $case = registration_decision_submitted($shell, Programme::factory()->create());
    app(RegistrationService::class)->approve(
        registration_decision_registrar(),
        (int) $case['registration']->id,
        registration_decision_request(),
    );
    Carbon::setTestNow(Carbon::parse('2026-11-15 00:00:00', 'Africa/Lagos'));

    expect(fn () => app(RegistrationService::class)->addDrop($case['user'], [], registration_decision_request()))
        ->toThrow(ValidationException::class, RegistrationService::closesAt('Registration', $shell['semester']->registration_deadline));
    expect($case['registration']->fresh()?->status)->toBe(RegistrationStatus::Approved);
});

it('refuses an add and drop that exceeds the maximum and keeps the row', function () {
    $shell = registration_decision_shell();
    $row = InstitutionSetting::query()->first();
    expect($row)->not->toBeNull();
    $row->max_units = 2;
    $row->save();
    app(SettingsService::class)->forgetCache();
    $programme = Programme::factory()->create();
    $case = registration_decision_submitted($shell, $programme);
    $elective = Course::factory()->create([
        'department_id' => $programme->department_id,
        'credit_units' => 2,
    ]);
    ProgrammeCourse::factory()->elective()->create([
        'programme_id' => $programme->id,
        'course_id' => $elective->id,
        'level' => 100,
        'semester_no' => 1,
    ]);
    app(RegistrationService::class)->approve(
        registration_decision_registrar(),
        (int) $case['registration']->id,
        registration_decision_request(),
    );
    Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00', 'Africa/Lagos'));

    expect(fn () => app(RegistrationService::class)->addDrop(
        $case['user'],
        [(int) $elective->id],
        registration_decision_request(),
    ))->toThrow(ValidationException::class, RegistrationService::MESSAGE_ABOVE_MAX);
    expect($case['registration']->fresh()?->status)->toBe(RegistrationStatus::Approved)
        ->and($case['registration']->fresh()?->total_units)->toBe(2);
});

it('creates an empty draft when an active student is reopened', function () {
    $shell = registration_decision_shell();
    $shell['semester']->registration_deadline = '2026-10-18 08:00:00';
    $shell['semester']->add_drop_deadline = '2026-10-18 09:00:00';
    $shell['semester']->save();
    $programme = Programme::factory()->create();
    $student = Student::factory()->create([
        'programme_id' => $programme->id,
        'level' => 100,
        'entry_session_id' => $shell['session']->id,
        'status' => StudentStatus::Active,
    ]);
    $registrar = registration_decision_registrar();
    $until = Carbon::parse('2026-10-18 18:00:00', 'Africa/Lagos');
    $opened = app(RegistrationService::class)->reopen(
        $registrar,
        (int) $student->id,
        'Late return',
        $until,
        registration_decision_request(),
    );
    $audit = AuditLog::query()->where('action', RegistrationService::ACTION_REOPENED)->first();
    $during = app(RegistrationService::class)->assess(
        $student,
        Carbon::parse('2026-10-18 17:59:59', 'Africa/Lagos'),
    );
    $closed = app(RegistrationService::class)->assess(
        $student,
        Carbon::parse('2026-10-18 18:00:00', 'Africa/Lagos'),
    );

    expect($opened->status)->toBe(RegistrationStatus::Draft)
        ->and($opened->total_units)->toBe(0)
        ->and($opened->items)->toHaveCount(0)
        ->and($opened->reopen_reason)->toBe('Late return')
        ->and($opened->reopened_by)->toBe($registrar->id)
        ->and($during['can_start'])->toBeTrue()
        ->and($closed['can_start'])->toBeFalse()
        ->and(array_key_exists('reopen_reason', $audit->after ?? []))->toBeFalse();
});

it('refuses to reopen a student who is not active', function () {
    $shell = registration_decision_shell();
    $student = Student::factory()->create([
        'programme_id' => Programme::factory()->create()->id,
        'level' => 100,
        'entry_session_id' => $shell['session']->id,
        'status' => StudentStatus::Suspended,
    ]);

    expect(fn () => app(RegistrationService::class)->reopen(
        registration_decision_registrar(),
        (int) $student->id,
        'Late return',
        Carbon::parse('2026-10-18 18:00:00', 'Africa/Lagos'),
        registration_decision_request(),
    ))->toThrow(ValidationException::class, RegistrationService::MESSAGE_NOT_ACTIVE);
    expect(CourseRegistration::query()->count())->toBe(0);
});

it('refuses a faculty admin who tries to reopen a student', function () {
    $shell = registration_decision_shell();
    $student = Student::factory()->create([
        'programme_id' => Programme::factory()->create()->id,
        'level' => 100,
        'entry_session_id' => $shell['session']->id,
        'status' => StudentStatus::Active,
    ]);
    $officer = User::factory()->create();
    RoleAssignment::factory()->facultyAdmin()->create(['user_id' => $officer->id]);

    expect(fn () => app(RegistrationService::class)->reopen(
        $officer,
        (int) $student->id,
        'Late return',
        Carbon::parse('2026-10-18 18:00:00', 'Africa/Lagos'),
        registration_decision_request(),
    ))->toThrow(AuthorizationException::class);
    expect(CourseRegistration::query()->count())->toBe(0);
});
