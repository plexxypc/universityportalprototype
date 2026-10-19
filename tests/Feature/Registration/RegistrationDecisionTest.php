<?php

declare(strict_types=1);

use App\Enums\CourseType;
use App\Enums\NotificationType;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseRegistration;
use App\Models\CourseRegistrationItem;
use App\Models\Department;
use App\Models\EmailOutbox;
use App\Models\InstitutionSetting;
use App\Models\Notification;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\RoleAssignment;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\RegistrationService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-18 12:00:00', 'Africa/Lagos'));
    app(SettingsService::class)->forgetCache();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * One active semester and the unit limits used by an approval.
 *
 * @return array{session: AcademicSession, semester: Semester}
 */
function registration_decision_shell(): array
{
    $session = AcademicSession::factory()->current()->create(['name' => '2026/2027']);
    $semester = Semester::factory()->active()->create([
        'session_id' => $session->id,
        'name' => 'First',
        'semester_no' => 1,
        'registration_deadline' => '2026-11-01 00:00:00',
        'add_drop_deadline' => '2026-11-15 00:00:00',
    ]);
    $row = InstitutionSetting::query()->first() ?? new InstitutionSetting;
    $row->fill([
        'name' => 'University Portal',
        'code' => 'UNI',
        'matric_pattern' => '{DEPT}/{YEAR}/{SEQ4}',
        'min_units' => 2,
        'max_units' => 24,
        'approval_required' => true,
        'withhold_results_for_debt' => false,
        'attendance_threshold' => 75,
        'require_minimum_payment' => false,
    ]);
    $row->save();
    app(SettingsService::class)->forgetCache();

    return compact('session', 'semester');
}

/**
 * @param  array{session: AcademicSession, semester: Semester}  $shell
 * @return array{student: Student, user: User, registration: CourseRegistration}
 */
function registration_decision_submitted(array $shell, Programme $programme): array
{
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
    ProgrammeCourse::factory()->create([
        'programme_id' => $programme->id,
        'course_id' => $core->id,
        'level' => 100,
        'semester_no' => 1,
        'type' => CourseType::Core,
    ]);
    $registration = app(RegistrationService::class)->submit($user, [], registration_decision_request());

    return compact('student', 'user', 'registration');
}

function registration_decision_request(): Request
{
    $request = Request::create('/staff/course-registration', 'POST');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    return $request;
}

/**
 * The version a queue would pass back for this registration.
 */
function registration_decision_version(CourseRegistration $registration): string
{
    $fresh = $registration->fresh() ?? $registration;

    return app(RegistrationService::class)->version($fresh);
}

/**
 * @return array{id: int, version: string}
 */
function registration_decision_preview(CourseRegistration $registration): array
{
    return [
        'id' => (int) $registration->id,
        'version' => registration_decision_version($registration),
    ];
}

function registration_decision_registrar(): User
{
    $user = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => Role::Registrar,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    return $user;
}

it('approves a submitted registration once', function () {
    $shell = registration_decision_shell();
    $programme = Programme::factory()->create();
    $case = registration_decision_submitted($shell, $programme);
    $registrar = registration_decision_registrar();
    $service = app(RegistrationService::class);
    $version = registration_decision_version($case['registration']);
    $approved = $service->approve($registrar, (int) $case['registration']->id, $version, registration_decision_request());
    $service->approve($registrar, (int) $case['registration']->id, $version, registration_decision_request());
    $email = EmailOutbox::query()->first();

    expect($approved->status)->toBe(RegistrationStatus::Approved)
        ->and($approved->decided_by)->toBe($registrar->id)
        ->and(Notification::query()->where('type', NotificationType::RegistrationConfirmed)->count())->toBe(1)
        ->and(EmailOutbox::query()->count())->toBe(1)
        ->and($email?->recipient_email)->toBe($case['user']->email)
        ->and($email?->related_type)->toBe('course_registrations')
        ->and($email?->related_id)->toBe($case['registration']->id)
        ->and($email?->secrets)->toBeNull()
        ->and($email?->template)->toBe('registration_confirmed')
        ->and(AuditLog::query()->where('action', RegistrationService::ACTION_APPROVED)->count())->toBe(1);
});

it('rejects with a reason and leaves that reason out of the notice', function () {
    $shell = registration_decision_shell();
    $programme = Programme::factory()->create();
    $case = registration_decision_submitted($shell, $programme);
    $registrar = registration_decision_registrar();
    $reason = 'Fee receipt missing';
    $rejected = app(RegistrationService::class)->reject(
        $registrar,
        (int) $case['registration']->id,
        $reason,
        registration_decision_version($case['registration']),
        registration_decision_request(),
    );
    $notice = Notification::query()->where('type', NotificationType::RegistrationRejected)->first();
    $audit = AuditLog::query()->where('action', RegistrationService::ACTION_REJECTED)->first();

    expect($rejected->status)->toBe(RegistrationStatus::Rejected)
        ->and($rejected->rejection_reason)->toBe($reason)
        ->and($notice?->data['message'] ?? null)->toBe(RegistrationService::NOTICE_REJECTED_MESSAGE)
        ->and($notice?->data['title'] ?? null)->toBe(RegistrationService::NOTICE_REJECTED_TITLE)
        ->and($notice?->data['link'] ?? null)->toBe(RegistrationService::NOTICE_LINK)
        ->and(array_key_exists('reason', $notice->data ?? []))->toBeFalse()
        ->and(EmailOutbox::query()->count())->toBe(0)
        ->and(array_key_exists('rejection_reason', $audit->after ?? []))->toBeFalse();
});

it('refuses a blank rejection reason', function () {
    $shell = registration_decision_shell();
    $programme = Programme::factory()->create();
    $case = registration_decision_submitted($shell, $programme);

    expect(fn () => app(RegistrationService::class)->reject(
        registration_decision_registrar(),
        (int) $case['registration']->id,
        '   ',
        registration_decision_version($case['registration']),
        registration_decision_request(),
    ))->toThrow(ValidationException::class, RegistrationService::MESSAGE_REASON);
    expect($case['registration']->fresh()?->status)->toBe(RegistrationStatus::Submitted);
});

it('refuses approval of a draft and keeps the row', function () {
    $shell = registration_decision_shell();
    $programme = Programme::factory()->create();
    $case = registration_decision_submitted($shell, $programme);
    $case['registration']->status = RegistrationStatus::Draft;
    $case['registration']->save();

    expect(fn () => app(RegistrationService::class)->approve(
        registration_decision_registrar(),
        (int) $case['registration']->id,
        registration_decision_version($case['registration']),
        registration_decision_request(),
    ))->toThrow(ValidationException::class, RegistrationService::MESSAGE_TRANSITION);
    expect($case['registration']->fresh()?->status)->toBe(RegistrationStatus::Draft)
        ->and(AuditLog::query()->count())->toBe(1)
        ->and(EmailOutbox::query()->count())->toBe(0);
});

it('returns the same 404 for a missing id and an id outside the department', function () {
    $shell = registration_decision_shell();
    $officer = User::factory()->create();
    $assignment = RoleAssignment::factory()->departmentOfficer()->create([
        'user_id' => $officer->id,
    ]);
    $inside = Programme::factory()->create(['department_id' => $assignment->department_id]);
    $outside = Programme::factory()->create();
    $home = registration_decision_submitted($shell, $inside);
    $other = registration_decision_submitted($shell, $outside);
    $missing = null;
    $hidden = null;

    try {
        app(RegistrationService::class)->approve($officer, 999999, 'missing', registration_decision_request());
    } catch (NotFoundHttpException $exception) {
        $missing = $exception;
    }

    try {
        app(RegistrationService::class)->reject($officer, (int) $other['registration']->id, 'Outside', 'missing', registration_decision_request());
    } catch (NotFoundHttpException $exception) {
        $hidden = $exception;
    }

    expect($missing)->toBeInstanceOf(NotFoundHttpException::class)
        ->and($hidden)->toBeInstanceOf(NotFoundHttpException::class)
        ->and($missing?->getStatusCode())->toBe(404)
        ->and($hidden?->getStatusCode())->toBe(404)
        ->and($missing?->getMessage())->toBe($hidden?->getMessage())
        ->and($home['registration']->fresh()?->status)->toBe(RegistrationStatus::Submitted)
        ->and($other['registration']->fresh()?->status)->toBe(RegistrationStatus::Submitted);

    $approved = app(RegistrationService::class)->approve(
        $officer,
        (int) $home['registration']->id,
        registration_decision_version($home['registration']),
        registration_decision_request(),
    );
    expect($approved->status)->toBe(RegistrationStatus::Approved);
});

it('returns the same 404 for a faculty admin outside that faculty', function () {
    $shell = registration_decision_shell();
    $officer = User::factory()->create();
    $assignment = RoleAssignment::factory()->facultyAdmin()->create([
        'user_id' => $officer->id,
    ]);
    $department = Department::factory()->create(['faculty_id' => $assignment->faculty_id]);
    $inside = Programme::factory()->create(['department_id' => $department->id]);
    $outside = Programme::factory()->create();
    $home = registration_decision_submitted($shell, $inside);
    $other = registration_decision_submitted($shell, $outside);
    $hidden = null;

    try {
        app(RegistrationService::class)->approve($officer, (int) $other['registration']->id, 'missing', registration_decision_request());
    } catch (NotFoundHttpException $exception) {
        $hidden = $exception;
    }

    expect($hidden)->toBeInstanceOf(NotFoundHttpException::class)
        ->and($hidden?->getStatusCode())->toBe(404);

    $approved = app(RegistrationService::class)->approve(
        $officer,
        (int) $home['registration']->id,
        registration_decision_version($home['registration']),
        registration_decision_request(),
    );
    expect($approved->status)->toBe(RegistrationStatus::Approved);
});

it('lets a registrar and a super admin approve any registration', function () {
    $shell = registration_decision_shell();
    $first = registration_decision_submitted($shell, Programme::factory()->create());
    $second = registration_decision_submitted($shell, Programme::factory()->create());
    $super = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $super->id,
        'role' => Role::SuperAdmin,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    $by_registrar = app(RegistrationService::class)->approve(
        registration_decision_registrar(),
        (int) $first['registration']->id,
        registration_decision_version($first['registration']),
        registration_decision_request(),
    );
    $by_super = app(RegistrationService::class)->approve(
        $super,
        (int) $second['registration']->id,
        registration_decision_version($second['registration']),
        registration_decision_request(),
    );

    expect($by_registrar->status)->toBe(RegistrationStatus::Approved)
        ->and($by_super->status)->toBe(RegistrationStatus::Approved);
});

it('refuses a bursar with 403', function () {
    $shell = registration_decision_shell();
    $case = registration_decision_submitted($shell, Programme::factory()->create());
    $bursar = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $bursar->id,
        'role' => Role::Bursar,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    expect(fn () => app(RegistrationService::class)->approve(
        $bursar,
        (int) $case['registration']->id,
        registration_decision_version($case['registration']),
        registration_decision_request(),
    ))->toThrow(AuthorizationException::class);
    expect($case['registration']->fresh()?->status)->toBe(RegistrationStatus::Submitted);
});

it('approves the submitted rows and counts the rest as skipped', function () {
    $shell = registration_decision_shell();
    $first = registration_decision_submitted($shell, Programme::factory()->create());
    $second = registration_decision_submitted($shell, Programme::factory()->create());
    $draft_case = registration_decision_submitted($shell, Programme::factory()->create());
    $draft_case['registration']->status = RegistrationStatus::Draft;
    $draft_case['registration']->save();
    $registrar = registration_decision_registrar();
    $previews = [
        registration_decision_preview($first['registration']),
        registration_decision_preview($second['registration']),
        registration_decision_preview($draft_case['registration']),
        ['id' => 999999, 'version' => 'missing'],
    ];
    $result = app(RegistrationService::class)->bulkApprove($registrar, $previews, registration_decision_request());
    $again = app(RegistrationService::class)->bulkApprove($registrar, $previews, registration_decision_request());
    $outbox_ids = EmailOutbox::query()->orderBy('related_id')->pluck('related_id')->map(fn (mixed $id): int => (int) $id)->all();

    expect($result)->toBe(['approved' => 2, 'skipped' => 2])
        ->and($again)->toBe(['approved' => 0, 'skipped' => 4])
        ->and(EmailOutbox::query()->count())->toBe(2)
        ->and($outbox_ids)->toBe([(int) $first['registration']->id, (int) $second['registration']->id])
        ->and(Notification::query()->where('type', NotificationType::RegistrationConfirmed)->count())->toBe(2)
        ->and($first['registration']->fresh()?->status)->toBe(RegistrationStatus::Approved)
        ->and($draft_case['registration']->fresh()?->status)->toBe(RegistrationStatus::Draft);
});

it('refuses a bulk list longer than 100 and changes nothing', function () {
    $shell = registration_decision_shell();
    $case = registration_decision_submitted($shell, Programme::factory()->create());
    $previews = array_fill(0, 101, registration_decision_preview($case['registration']));

    expect(fn () => app(RegistrationService::class)->bulkApprove(
        registration_decision_registrar(),
        $previews,
        registration_decision_request(),
    ))->toThrow(ValidationException::class, RegistrationService::MESSAGE_BULK_CAP);
    expect($case['registration']->fresh()?->status)->toBe(RegistrationStatus::Submitted)
        ->and(EmailOutbox::query()->count())->toBe(0);
});

it('approves when a confirmation email cannot be queued', function () {
    $logged = [];
    Log::listen(function (MessageLogged $event) use (&$logged): void {
        $logged[] = ['message' => (string) $event->message, 'context' => $event->context];
    });
    $shell = registration_decision_shell();
    $blocked = registration_decision_submitted($shell, Programme::factory()->create());
    $also_blocked = registration_decision_submitted($shell, Programme::factory()->create());
    $clear = registration_decision_submitted($shell, Programme::factory()->create());
    $blocked['user']->email = 'not-an-email';
    $blocked['user']->save();
    $also_blocked['user']->email = 'also-not-an-email';
    $also_blocked['user']->save();
    $registrar = registration_decision_registrar();
    $service = app(RegistrationService::class);
    $approved = $service->approve(
        $registrar,
        (int) $blocked['registration']->id,
        registration_decision_version($blocked['registration']),
        registration_decision_request(),
    );
    $result = $service->bulkApprove($registrar, [
        registration_decision_preview($also_blocked['registration']),
        registration_decision_preview($clear['registration']),
    ], registration_decision_request());

    expect($approved->status)->toBe(RegistrationStatus::Approved)
        ->and($result)->toBe(['approved' => 2, 'skipped' => 0])
        ->and($also_blocked['registration']->fresh()?->status)->toBe(RegistrationStatus::Approved)
        ->and($clear['registration']->fresh()?->status)->toBe(RegistrationStatus::Approved)
        ->and(Notification::query()->where('type', NotificationType::RegistrationConfirmed)->count())->toBe(3)
        ->and(EmailOutbox::query()->count())->toBe(1)
        ->and(EmailOutbox::query()->value('recipient_email'))->toBe($clear['user']->email)
        ->and($logged)->toBe([
            ['message' => RegistrationService::WARNING_EMAIL, 'context' => []],
            ['message' => RegistrationService::WARNING_EMAIL, 'context' => []],
        ])
        ->and(json_encode($logged))->not->toContain('not-an-email')
        ->and(json_encode($logged))->not->toContain('also-not-an-email');
});

it('refuses a staff member who tries to approve their own registration', function () {
    $shell = registration_decision_shell();
    $registrar = registration_decision_registrar();
    $programme = Programme::factory()->create();
    $student = Student::factory()->create([
        'user_id' => $registrar->id,
        'programme_id' => $programme->id,
        'level' => 100,
        'entry_session_id' => $shell['session']->id,
        'status' => StudentStatus::Active,
    ]);
    $core = Course::factory()->create([
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
    $saved = app(RegistrationService::class)->submit($registrar, [], registration_decision_request());
    $version = registration_decision_version($saved);

    expect(fn () => app(RegistrationService::class)->approve(
        $registrar,
        (int) $saved->id,
        $version,
        registration_decision_request(),
    ))->toThrow(AuthorizationException::class);

    $bulk = app(RegistrationService::class)->bulkApprove(
        $registrar,
        [registration_decision_preview($saved)],
        registration_decision_request(),
    );

    expect($saved->fresh()?->status)->toBe(RegistrationStatus::Submitted)
        ->and($bulk)->toBe(['approved' => 0, 'skipped' => 1])
        ->and(EmailOutbox::query()->count())->toBe(0)
        ->and($student->user_id)->toBe($registrar->id);

    $approved = app(RegistrationService::class)->approve(
        registration_decision_registrar(),
        (int) $saved->id,
        $version,
        registration_decision_request(),
    );

    expect($approved->status)->toBe(RegistrationStatus::Approved)
        ->and(EmailOutbox::query()->count())->toBe(1);
});

it('refuses approval when the registration changed after it was loaded', function () {
    $shell = registration_decision_shell();
    $programme = Programme::factory()->create();
    $case = registration_decision_submitted($shell, $programme);
    $other = registration_decision_submitted($shell, Programme::factory()->create());
    $loaded = registration_decision_version($case['registration']);
    $item = CourseRegistrationItem::query()->where('course_registration_id', $case['registration']->id)->first();
    expect($item)->not->toBeNull();
    $item->credit_units = 9;
    $item->save();
    $case['registration']->total_units = 9;
    $case['registration']->save();

    expect(fn () => app(RegistrationService::class)->approve(
        registration_decision_registrar(),
        (int) $case['registration']->id,
        $loaded,
        registration_decision_request(),
    ))->toThrow(ValidationException::class, RegistrationService::MESSAGE_RELOAD);

    $result = app(RegistrationService::class)->bulkApprove(
        registration_decision_registrar(),
        [
            ['id' => (int) $case['registration']->id, 'version' => $loaded],
            registration_decision_preview($other['registration']),
        ],
        registration_decision_request(),
    );

    expect($case['registration']->fresh()?->status)->toBe(RegistrationStatus::Submitted)
        ->and((int) $case['registration']->fresh()?->total_units)->toBe(9)
        ->and($result)->toBe(['approved' => 1, 'skipped' => 1])
        ->and($other['registration']->fresh()?->status)->toBe(RegistrationStatus::Approved)
        ->and(EmailOutbox::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', RegistrationService::ACTION_APPROVED)->count())->toBe(1);
});
