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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-18 12:00:00', 'Africa/Lagos'));
    app(SettingsService::class)->forgetCache();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * An active student, an open semester numbered 1, and one core plus one elective.
 *
 * @return array{
 *     student: Student,
 *     user: User,
 *     semester: Semester,
 *     session: AcademicSession,
 *     programme: Programme,
 *     core: Course,
 *     elective: Course
 * }
 */
function registration_submit_world(): array
{
    $session = AcademicSession::factory()->current()->create(['name' => '2026/2027']);
    $semester = Semester::factory()->active()->create([
        'session_id' => $session->id,
        'name' => 'First',
        'semester_no' => 1,
        'registration_deadline' => '2026-11-01 00:00:00',
        'add_drop_deadline' => '2026-11-15 00:00:00',
    ]);
    $programme = Programme::factory()->create();
    $student = Student::factory()->create([
        'programme_id' => $programme->id,
        'level' => 100,
        'entry_session_id' => $session->id,
        'status' => StudentStatus::Active,
    ]);
    $user = $student->user;
    expect($user)->toBeInstanceOf(User::class);
    $core = Course::factory()->create([
        'department_id' => $programme->department_id,
        'code' => 'CSC101',
        'credit_units' => 2,
    ]);
    $elective = Course::factory()->create([
        'department_id' => $programme->department_id,
        'code' => 'CSC102',
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

    return compact('student', 'user', 'semester', 'session', 'programme', 'core', 'elective');
}

/**
 * @param  array{programme: Programme, session: AcademicSession}  $world
 */
function registration_submit_student(array $world): Student
{
    return Student::factory()->create([
        'programme_id' => $world['programme']->id,
        'level' => 100,
        'entry_session_id' => $world['session']->id,
        'status' => StudentStatus::Active,
    ]);
}

function registration_submit_limits(int $min_units, int $max_units, bool $approval_required = true): void
{
    $row = InstitutionSetting::query()->first() ?? new InstitutionSetting;
    $row->fill([
        'name' => 'University Portal',
        'code' => 'UNI',
        'matric_pattern' => '{DEPT}/{YEAR}/{SEQ4}',
        'min_units' => $min_units,
        'max_units' => $max_units,
        'approval_required' => $approval_required,
        'withhold_results_for_debt' => false,
        'attendance_threshold' => 75,
        'require_minimum_payment' => false,
    ]);
    $row->save();
    app(SettingsService::class)->forgetCache();
}

function registration_submit_request(): Request
{
    $request = Request::create('/student/course-registration', 'POST');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    return $request;
}

it('saves a draft outside the unit limits', function () {
    $world = registration_submit_world();
    $service = app(RegistrationService::class);
    $draft = $service->saveDraft($world['user'], [], registration_submit_request());
    $service->saveDraft($world['user'], [], registration_submit_request());

    expect($draft->status)->toBe(RegistrationStatus::Draft)
        ->and($draft->total_units)->toBe(2)
        ->and($draft->items)->toHaveCount(1)
        ->and((int) $draft->items->first()?->credit_units)->toBe(2)
        ->and(AuditLog::query()->where('action', RegistrationService::ACTION_SAVED)->count())->toBe(1)
        ->and(Notification::query()->count())->toBe(0)
        ->and(EmailOutbox::query()->count())->toBe(0);
});

it('submits at the minimum and at the maximum', function () {
    $world = registration_submit_world();
    registration_submit_limits(2, 4);
    $service = app(RegistrationService::class);
    $minimum = $service->submit($world['user'], [], registration_submit_request());
    $other = registration_submit_student($world);
    $other_user = $other->user;
    expect($other_user)->toBeInstanceOf(User::class);
    $maximum = $service->submit(
        $other_user,
        [(int) $world['elective']->id],
        registration_submit_request(),
    );

    expect($minimum->status)->toBe(RegistrationStatus::Submitted)
        ->and($minimum->total_units)->toBe(2)
        ->and($maximum->status)->toBe(RegistrationStatus::Submitted)
        ->and($maximum->total_units)->toBe(4)
        ->and(EmailOutbox::query()->count())->toBe(0);
});

it('refuses 14 and 25 units and accepts 15 and 24', function () {
    $world = registration_submit_world();
    registration_submit_limits(15, 24);
    $service = app(RegistrationService::class);
    $world['core']->credit_units = 14;
    $world['core']->save();

    expect(fn () => $service->submit($world['user'], [], registration_submit_request()))
        ->toThrow(ValidationException::class, RegistrationService::MESSAGE_BELOW_MIN);
    expect(CourseRegistration::query()->count())->toBe(0);

    $world['core']->credit_units = 15;
    $world['core']->save();
    $accepted_minimum = $service->submit($world['user'], [], registration_submit_request());
    $at_maximum = registration_submit_student($world);
    $at_maximum_user = $at_maximum->user;
    expect($at_maximum_user)->toBeInstanceOf(User::class);
    $world['elective']->credit_units = 9;
    $world['elective']->save();
    $accepted_maximum = $service->submit(
        $at_maximum_user,
        [(int) $world['elective']->id],
        registration_submit_request(),
    );
    $over = registration_submit_student($world);
    $over_user = $over->user;
    expect($over_user)->toBeInstanceOf(User::class);
    $world['elective']->credit_units = 10;
    $world['elective']->save();

    expect(fn () => $service->submit($over_user, [(int) $world['elective']->id], registration_submit_request()))
        ->toThrow(ValidationException::class, RegistrationService::MESSAGE_ABOVE_MAX);

    expect($accepted_minimum->status)->toBe(RegistrationStatus::Submitted)
        ->and($accepted_minimum->total_units)->toBe(15)
        ->and($accepted_maximum->status)->toBe(RegistrationStatus::Submitted)
        ->and($accepted_maximum->total_units)->toBe(24)
        ->and(CourseRegistration::query()->where('student_id', $over->id)->count())->toBe(0);
});

it('copies credit units and stores a total equal to the items', function () {
    $world = registration_submit_world();
    registration_submit_limits(2, 24);
    $saved = app(RegistrationService::class)->submit(
        $world['user'],
        [(int) $world['elective']->id],
        registration_submit_request(),
    );
    $world['core']->credit_units = 9;
    $world['core']->save();
    $world['elective']->credit_units = 9;
    $world['elective']->save();
    $saved->refresh();
    $saved->load('items');
    $sum = 0;

    foreach ($saved->items as $item) {
        $sum += (int) $item->credit_units;
    }

    expect($saved->items)->toHaveCount(2)
        ->and($sum)->toBe(4)
        ->and($saved->total_units)->toBe($sum)
        ->and((int) $world['core']->fresh()?->credit_units)->toBe(9);

    foreach (['saveDraft', 'submit', 'addDrop'] as $name) {
        $parameters = array_map(
            fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionMethod(RegistrationService::class, $name))->getParameters(),
        );

        expect($parameters)->toBe(['actor', 'course_ids', 'request']);
    }
});

it('approves and sends one confirmation when approval is off', function () {
    $world = registration_submit_world();
    registration_submit_limits(2, 24, false);
    $world['user']->name = 'Ada Lovelace';
    $world['user']->save();
    $service = app(RegistrationService::class);
    $saved = $service->submit($world['user'], [], registration_submit_request());
    $service->submit($world['user'], [], registration_submit_request());
    $notice = Notification::query()->first();
    $email = EmailOutbox::query()->first();
    $audit = AuditLog::query()->where('action', RegistrationService::ACTION_SUBMITTED)->first();

    expect($saved->fresh()?->status)->toBe(RegistrationStatus::Approved)
        ->and($saved->decided_by)->toBeNull()
        ->and($saved->decided_at)->not->toBeNull()
        ->and($saved->submitted_at)->not->toBeNull()
        ->and(Notification::query()->count())->toBe(1)
        ->and($notice?->type)->toBe(NotificationType::RegistrationConfirmed)
        ->and($notice?->data['message'] ?? null)->toBe(RegistrationService::NOTICE_CONFIRMED_MESSAGE)
        ->and(EmailOutbox::query()->count())->toBe(1)
        ->and($email?->template)->toBe('registration_confirmed')
        ->and($email?->related_type)->toBe('course_registrations')
        ->and($email?->related_id)->toBe($saved->id)
        ->and($email?->secrets)->toBeNull()
        ->and($email?->body_text)->toContain('is confirmed')
        ->and($email?->body_text)->toContain($world['user']->name)
        ->and(AuditLog::query()->where('action', RegistrationService::ACTION_SUBMITTED)->count())->toBe(1)
        ->and($audit?->after['auto_approved'] ?? null)->toBeTrue()
        ->and($audit?->before)->toBeNull();
});

it('notifies without email when approval is required', function () {
    $world = registration_submit_world();
    registration_submit_limits(2, 24, true);
    $saved = app(RegistrationService::class)->submit($world['user'], [], registration_submit_request());
    $notice = Notification::query()->first();

    expect($saved->status)->toBe(RegistrationStatus::Submitted)
        ->and($saved->decided_at)->toBeNull()
        ->and(EmailOutbox::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(1)
        ->and($notice?->type)->toBe(NotificationType::RegistrationSubmitted)
        ->and($notice?->data['message'] ?? null)->toBe(RegistrationService::NOTICE_SUBMITTED_MESSAGE)
        ->and(array_key_exists('reason', $notice->data ?? []))->toBeFalse();
});

it('does not write again when the same courses are submitted twice', function () {
    $world = registration_submit_world();
    registration_submit_limits(2, 24);
    $service = app(RegistrationService::class);
    $service->submit($world['user'], [], registration_submit_request());
    $service->submit($world['user'], [], registration_submit_request());

    expect(CourseRegistration::query()->count())->toBe(1)
        ->and(Notification::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', RegistrationService::ACTION_SUBMITTED)->count())->toBe(1)
        ->and(EmailOutbox::query()->count())->toBe(0);
});

it('refuses a draft save after submit and keeps the row', function () {
    $world = registration_submit_world();
    registration_submit_limits(2, 24);
    $service = app(RegistrationService::class);
    $saved = $service->submit($world['user'], [(int) $world['elective']->id], registration_submit_request());
    $item_ids = CourseRegistrationItem::query()->orderBy('course_id')->pluck('course_id')->all();

    expect(fn () => $service->saveDraft($world['user'], [], registration_submit_request()))
        ->toThrow(ValidationException::class, RegistrationService::MESSAGE_TRANSITION);

    $fresh = $saved->fresh();
    expect($fresh?->status)->toBe(RegistrationStatus::Submitted)
        ->and($fresh?->total_units)->toBe(4)
        ->and(CourseRegistrationItem::query()->orderBy('course_id')->pluck('course_id')->all())->toBe($item_ids)
        ->and(AuditLog::query()->where('action', RegistrationService::ACTION_SUBMITTED)->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(1);
});

it('refuses submit at the deadline second and accepts the second before', function () {
    $world = registration_submit_world();
    registration_submit_limits(2, 24);
    Carbon::setTestNow(Carbon::parse('2026-10-31 23:59:59', 'Africa/Lagos'));
    $saved = app(RegistrationService::class)->submit($world['user'], [], registration_submit_request());
    $late = registration_submit_student($world);
    $late_user = $late->user;
    expect($late_user)->toBeInstanceOf(User::class);
    Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00', 'Africa/Lagos'));
    $message = null;

    try {
        app(RegistrationService::class)->submit($late_user, [], registration_submit_request());
    } catch (ValidationException $exception) {
        $errors = $exception->errors()['registration'] ?? [];
        $message = is_string($errors[0] ?? null) ? $errors[0] : null;
    }

    expect($saved->status)->toBe(RegistrationStatus::Submitted)
        ->and($message)->toBe(RegistrationService::closesAt('Add/drop', $world['semester']->add_drop_deadline))
        ->and(CourseRegistration::query()->where('student_id', $late->id)->count())->toBe(0);
});

it('repeats a submit at the deadline without a second notice', function () {
    $world = registration_submit_world();
    registration_submit_limits(2, 24);
    Carbon::setTestNow(Carbon::parse('2026-10-31 23:59:59', 'Africa/Lagos'));
    $service = app(RegistrationService::class);
    $service->submit($world['user'], [], registration_submit_request());
    Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00', 'Africa/Lagos'));
    $service->submit($world['user'], [], registration_submit_request());

    expect(Notification::query()->count())->toBe(1)
        ->and(CourseRegistration::query()->where('status', RegistrationStatus::Submitted)->count())->toBe(1);
});

it('refuses a super admin and a user who is not a student', function () {
    $world = registration_submit_world();
    registration_submit_limits(2, 24);
    RoleAssignment::factory()->create([
        'user_id' => $world['user']->id,
        'role' => Role::SuperAdmin,
        'faculty_id' => null,
        'department_id' => null,
    ]);
    $staff = User::factory()->create();

    expect(fn () => app(RegistrationService::class)->submit($world['user'], [], registration_submit_request()))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(RegistrationService::class)->submit($staff, [], registration_submit_request()))
        ->toThrow(AuthorizationException::class);
    expect(CourseRegistration::query()->count())->toBe(0);
});

it('refuses a student who is not active', function () {
    $world = registration_submit_world();
    registration_submit_limits(2, 24);
    $world['student']->status = StudentStatus::Suspended;
    $world['student']->save();

    expect(fn () => app(RegistrationService::class)->submit($world['user'], [], registration_submit_request()))
        ->toThrow(ValidationException::class, RegistrationService::MESSAGE_NOT_ACTIVE);
    expect(CourseRegistration::query()->count())->toBe(0);
});

it('submits a rejected registration again', function () {
    $world = registration_submit_world();
    registration_submit_limits(2, 24);
    $service = app(RegistrationService::class);
    $saved = $service->submit($world['user'], [], registration_submit_request());
    $saved->status = RegistrationStatus::Rejected;
    $saved->rejection_reason = 'Missing document';
    $saved->decided_at = now();
    $saved->save();
    $again = $service->submit($world['user'], [], registration_submit_request());

    expect($again->status)->toBe(RegistrationStatus::Submitted)
        ->and($again->rejection_reason)->toBeNull()
        ->and($again->decided_at)->toBeNull()
        ->and(Notification::query()->count())->toBe(2)
        ->and(EmailOutbox::query()->count())->toBe(0);
});

it('keeps the approval when the confirmation email cannot be queued', function () {
    $logged = [];
    Log::listen(function (MessageLogged $event) use (&$logged): void {
        $logged[] = ['message' => (string) $event->message, 'context' => $event->context];
    });
    $world = registration_submit_world();
    registration_submit_limits(2, 24, false);
    $world['user']->email = 'not-an-email';
    $world['user']->save();
    $saved = app(RegistrationService::class)->submit($world['user'], [], registration_submit_request());
    $notice = Notification::query()->first();

    expect($saved->status)->toBe(RegistrationStatus::Approved)
        ->and(CourseRegistration::query()->count())->toBe(1)
        ->and(Notification::query()->count())->toBe(1)
        ->and($notice?->type)->toBe(NotificationType::RegistrationConfirmed)
        ->and(EmailOutbox::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', RegistrationService::ACTION_SUBMITTED)->count())->toBe(1)
        ->and($logged)->toBe([
            ['message' => RegistrationService::WARNING_EMAIL, 'context' => []],
        ])
        ->and(json_encode($logged))->not->toContain('not-an-email');
});

it('refuses a course that is not eligible and writes nothing', function () {
    $world = registration_submit_world();
    registration_submit_limits(2, 24);

    expect(fn () => app(RegistrationService::class)->submit($world['user'], [999999], registration_submit_request()))
        ->toThrow(ValidationException::class, RegistrationService::MESSAGE_NOT_ELIGIBLE);
    expect(CourseRegistration::query()->count())->toBe(0);
});

it('keeps one registration when two processes submit the same courses', function () {
    if (! registration_race_available()) {
        test()->markTestSkipped('pcntl and posix are required');
    }

    $connection = DB::connection();
    $wrapped = $connection->transactionLevel() > 0;

    if ($wrapped) {
        $connection->rollBack();
    }

    $children = [];
    $files = [];

    try {
        expect(config('database.connections.mysql.database'))->toBe('university_portal_testing')
            ->and(config('database.connections.mysql.host'))->toBe('127.0.0.1');
        $world = registration_submit_world();
        $world['semester']->registration_deadline = '2099-01-01 00:00:00';
        $world['semester']->add_drop_deadline = '2099-01-15 00:00:00';
        $world['semester']->save();
        registration_submit_limits(2, 24, true);
        $go = tempnam(sys_get_temp_dir(), 'reg-go');
        expect($go)->toBeString();
        $files[] = $go;

        if (is_file($go)) {
            unlink($go);
        }

        $results = [];

        for ($copy = 0; $copy < 2; $copy++) {
            $ready = tempnam(sys_get_temp_dir(), 'reg-ready');
            $result = tempnam(sys_get_temp_dir(), 'reg-result');
            $payload = tempnam(sys_get_temp_dir(), 'reg-payload');
            expect($ready)->toBeString()->and($result)->toBeString()->and($payload)->toBeString();

            if (is_file($ready)) {
                unlink($ready);
            }

            if (is_file($result)) {
                unlink($result);
            }

            file_put_contents((string) $payload, json_encode([
                'user_id' => $world['user']->id,
                'course_ids' => [(int) $world['core']->id],
                'copy' => $copy,
            ], JSON_THROW_ON_ERROR));
            $files[] = (string) $ready;
            $files[] = (string) $result;
            $files[] = (string) $payload;
            $results[] = (string) $result;
            $children[] = registration_race_open((string) $payload, (string) $ready, (string) $go, (string) $result);
        }

        $ready_deadline = time() + 20;
        $both_ready = false;

        while (time() < $ready_deadline) {
            $both_ready = is_file($files[1]) && is_file($files[4]);

            if ($both_ready) {
                break;
            }

            usleep(50000);
        }

        expect($both_ready)->toBeTrue();
        file_put_contents((string) $go, '1');
        registration_race_wait($results);
        $first = registration_race_read($results[0]);
        $second = registration_race_read($results[1]);

        expect([$first['ok'], $second['ok']])->toBe([true, true])
            ->and(CourseRegistration::query()->count())->toBe(1)
            ->and(CourseRegistration::query()->first()?->status)->toBe(RegistrationStatus::Submitted)
            ->and(Notification::query()->count())->toBe(1)
            ->and(AuditLog::query()->where('action', RegistrationService::ACTION_SUBMITTED)->count())->toBe(1)
            ->and(EmailOutbox::query()->count())->toBe(0);
    } finally {
        registration_race_finish($children, $files, $wrapped);
    }
});

it('lets one of two approvers confirm the same registration', function () {
    if (! registration_race_available()) {
        test()->markTestSkipped('pcntl and posix are required');
    }

    $connection = DB::connection();
    $wrapped = $connection->transactionLevel() > 0;

    if ($wrapped) {
        $connection->rollBack();
    }

    $children = [];
    $files = [];

    try {
        expect(config('database.connections.mysql.database'))->toBe('university_portal_testing')
            ->and(config('database.connections.mysql.host'))->toBe('127.0.0.1');
        $world = registration_submit_world();
        $world['semester']->registration_deadline = '2099-01-01 00:00:00';
        $world['semester']->add_drop_deadline = '2099-01-15 00:00:00';
        $world['semester']->save();
        registration_submit_limits(2, 24, true);
        $saved = app(RegistrationService::class)->submit($world['user'], [], registration_submit_request());
        $registrar = User::factory()->create();
        RoleAssignment::factory()->create([
            'user_id' => $registrar->id,
            'role' => Role::Registrar,
            'faculty_id' => null,
            'department_id' => null,
        ]);
        $go = tempnam(sys_get_temp_dir(), 'appr-go');
        expect($go)->toBeString();
        $files[] = $go;

        if (is_file($go)) {
            unlink($go);
        }

        $results = [];

        for ($copy = 0; $copy < 2; $copy++) {
            $ready = tempnam(sys_get_temp_dir(), 'appr-ready');
            $result = tempnam(sys_get_temp_dir(), 'appr-result');
            $payload = tempnam(sys_get_temp_dir(), 'appr-payload');
            expect($ready)->toBeString()->and($result)->toBeString()->and($payload)->toBeString();

            if (is_file($ready)) {
                unlink($ready);
            }

            if (is_file($result)) {
                unlink($result);
            }

            file_put_contents((string) $payload, json_encode([
                'action' => 'approve',
                'user_id' => $registrar->id,
                'registration_id' => $saved->id,
                'version' => app(RegistrationService::class)->version($saved),
                'copy' => $copy,
            ], JSON_THROW_ON_ERROR));
            $files[] = (string) $ready;
            $files[] = (string) $result;
            $files[] = (string) $payload;
            $results[] = (string) $result;
            $children[] = registration_race_open((string) $payload, (string) $ready, (string) $go, (string) $result);
        }

        $ready_deadline = time() + 20;
        $both_ready = false;

        while (time() < $ready_deadline) {
            $both_ready = is_file($files[1]) && is_file($files[4]);

            if ($both_ready) {
                break;
            }

            usleep(50000);
        }

        expect($both_ready)->toBeTrue();
        file_put_contents((string) $go, '1');
        registration_race_wait($results);

        expect(registration_race_read($results[0])['ok'])->toBeTrue()
            ->and(registration_race_read($results[1])['ok'])->toBeTrue()
            ->and(CourseRegistration::query()->first()?->status)->toBe(RegistrationStatus::Approved)
            ->and(Notification::query()->where('type', NotificationType::RegistrationConfirmed)->count())->toBe(1)
            ->and(EmailOutbox::query()->where('template', 'registration_confirmed')->count())->toBe(1)
            ->and(AuditLog::query()->where('action', RegistrationService::ACTION_APPROVED)->count())->toBe(1);
    } finally {
        registration_race_finish($children, $files, $wrapped);
    }
});

function registration_race_available(): bool
{
    return function_exists('posix_kill')
        && function_exists('proc_open')
        && extension_loaded('pcntl')
        && defined('SIGKILL')
        && defined('SIGTERM');
}

/**
 * @return array<string, string>
 */
function registration_race_env(): array
{
    $env = [];
    $inherited = getenv();

    if (is_array($inherited)) {
        foreach ($inherited as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }
    }

    $env['DB_CONNECTION'] = 'mysql';
    $env['DB_HOST'] = '127.0.0.1';
    $env['DB_PORT'] = '3306';
    $env['DB_DATABASE'] = 'university_portal_testing';
    $env['DB_USERNAME'] = $env['DB_USERNAME'] ?? 'portal';
    $env['DB_PASSWORD'] = $env['DB_PASSWORD'] ?? 'portal';
    $env['DB_URL'] = '';
    $env['APP_ENV'] = 'testing';
    $env['MAIL_MAILER'] = 'array';
    $env['QUEUE_CONNECTION'] = 'sync';

    return $env;
}

/**
 * @return array{process: resource, pid: int}
 */
function registration_race_open(string $payload, string $ready, string $go, string $result): array
{
    $sink = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = proc_open(
        [
            PHP_BINARY,
            base_path('tests/Support/registration_race_child.php'),
            $ready,
            $go,
            $result,
            $payload,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $sink, 'w'],
            2 => ['file', $sink, 'w'],
        ],
        $pipes,
        base_path(),
        registration_race_env(),
    );

    expect(is_resource($process))->toBeTrue();

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    $status = proc_get_status($process);

    return [
        'process' => $process,
        'pid' => (int) $status['pid'],
    ];
}

/**
 * @param  list<string>  $results
 */
function registration_race_wait(array $results): void
{
    $deadline = time() + 25;

    while (time() < $deadline) {
        $ready = true;

        foreach ($results as $result) {
            if (! is_file($result)) {
                $ready = false;
            }
        }

        if ($ready) {
            return;
        }

        usleep(50000);
    }
}

/**
 * @return array{ok: bool, code: string}
 */
function registration_race_read(string $path): array
{
    $contents = is_file($path) ? file_get_contents($path) : false;
    $decoded = is_string($contents) ? json_decode($contents, true) : null;

    if (! is_array($decoded)) {
        return ['ok' => false, 'code' => 'timeout'];
    }

    return [
        'ok' => (bool) ($decoded['ok'] ?? false),
        'code' => is_string($decoded['code'] ?? null) ? $decoded['code'] : 'failed',
    ];
}

/**
 * @param  list<array{process: resource, pid: int}>  $children
 * @param  list<string>  $files
 */
function registration_race_finish(array $children, array $files, bool $wrapped): void
{
    foreach ($children as $child) {
        $status = proc_get_status($child['process']);

        if ($status['running'] && function_exists('posix_kill')) {
            posix_kill($child['pid'], SIGKILL);
        }

        proc_close($child['process']);
    }

    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    if (config('database.connections.mysql.database') === 'university_portal_testing'
        && config('database.connections.mysql.host') === '127.0.0.1') {
        DB::table('email_outbox')->delete();
        DB::table('notifications')->delete();
        DB::table('audit_logs')->delete();
        DB::table('course_registration_items')->delete();
        DB::table('course_registrations')->delete();
        DB::table('programme_courses')->delete();
        DB::table('course_prerequisites')->delete();
        DB::table('courses')->delete();
        DB::table('students')->delete();
        DB::table('semesters')->delete();
        DB::table('academic_sessions')->delete();
        DB::table('programmes')->delete();
        DB::table('departments')->delete();
        DB::table('faculties')->delete();
        DB::table('role_assignments')->delete();
        DB::table('users')->delete();
        DB::table('institution_settings')->delete();
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();
        DB::table('cache')->delete();
        DB::table('cache_locks')->delete();
    }

    if ($wrapped && DB::connection()->transactionLevel() === 0) {
        DB::connection()->beginTransaction();
    }
}
