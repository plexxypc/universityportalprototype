<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Counter;
use App\Models\Department;
use App\Models\InstitutionSetting;
use App\Models\Programme;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\StudentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function student_input(Programme $programme, array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'other_names' => 'Mary',
        'email' => 'ada.lovelace@example.com',
        'phone' => '08012345678',
        'gender' => 'Female',
        'date_of_birth' => '2004-05-01',
        'state_of_origin' => 'Lagos',
        'address' => '12 Garden Close',
        'programme_id' => $programme->id,
        'level' => 100,
        'entry_session_id' => null,
    ], $overrides);
}

function student_actor(Role $role = Role::Registrar): User
{
    $user = User::factory()->create();

    if ($role === Role::FacultyAdmin) {
        RoleAssignment::factory()->facultyAdmin()->create([
            'user_id' => $user->id,
        ]);

        return $user;
    }

    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

function student_request(): Request
{
    return Request::create('/staff/students', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.20',
    ]);
}

function student_programme(string $code = 'CSC', int $years = 4): Programme
{
    if (Department::query()->where('code', $code)->exists()) {
        $code .= (string) (Department::query()->count() + 1);
    }

    $department = Department::factory()->create([
        'code' => $code,
        'name' => $code.' Department',
    ]);

    return Programme::factory()->create([
        'department_id' => $department->id,
        'code' => $code,
        'name' => $code.' Programme',
        'duration_years' => $years,
    ]);
}

function student_session(): AcademicSession
{
    $current = AcademicSession::query()->where('is_current', true)->first();

    if ($current instanceof AcademicSession) {
        return $current;
    }

    return AcademicSession::factory()->current()->create([
        'name' => '2026/2027',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function student_make(array $overrides = [], ?Programme $programme = null): Student
{
    $programme ??= student_programme();
    student_session();

    return app(StudentService::class)->create(
        student_actor(),
        student_input($programme, $overrides),
        student_request(),
    )->student;
}

/**
 * @return array<string, mixed>
 */
function student_field_error(callable $callback, string $field): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('builds the user name from the three name parts', function () {
    $student = student_make([
        'first_name' => '  Ada  ',
        'other_names' => ' Mary ',
        'last_name' => ' Lovelace ',
    ]);

    expect($student->user->name)->toBe('Ada Mary Lovelace')
        ->and($student->first_name)->toBe('Ada')
        ->and($student->other_names)->toBe('Mary')
        ->and($student->last_name)->toBe('Lovelace');

    $plain = student_make([
        'email' => 'ada.plain@example.com',
        'other_names' => '',
    ]);

    expect($plain->user->name)->toBe('Ada Lovelace');
});

it('defaults a blank entry session to the current session', function () {
    $student = student_make();
    $current = AcademicSession::query()->where('is_current', true)->first();

    expect($student->entry_session_id)->toBe($current?->id);
});

it('refuses to create a student when no current session exists', function () {
    $programme = student_programme();

    $errors = student_field_error(fn () => app(StudentService::class)->create(
        student_actor(),
        student_input($programme, ['email' => 'no.session@example.com']),
        student_request(),
    ), 'entry_session_id');

    expect($errors['entry_session_id'][0] ?? null)->toBe(StudentService::MESSAGE_SESSION)
        ->and(User::query()->where('email', 'no.session@example.com')->exists())->toBeFalse();
});

it('refuses an email that already exists and creates nothing', function () {
    $programme = student_programme();
    student_session();
    $actor = student_actor();
    $service = app(StudentService::class);
    $service->create($actor, student_input($programme), student_request());
    $before = User::query()->count();

    $errors = student_field_error(fn () => $service->create($actor, student_input($programme, [
        'email' => 'Ada.Lovelace@example.com',
    ]), student_request()), 'email');

    expect($errors['email'][0] ?? null)->toBe(StudentService::MESSAGE_EMAIL_TAKEN)
        ->and(User::query()->count())->toBe($before)
        ->and(Student::query()->count())->toBe(1);
});

it('normalises a phone number to +234', function () {
    $student = student_make(['phone' => '080 1234 5678']);

    expect($student->user->phone)->toBe('+2348012345678');
});

it('allocates the matric number from the counter for the pattern', function () {
    $programme = student_programme('CSC');
    student_session();
    $service = app(StudentService::class);
    $actor = student_actor();

    $first = $service->create($actor, student_input($programme), student_request())->student;
    $second = $service->create($actor, student_input($programme, [
        'email' => 'grace.hopper@example.com',
    ]), student_request())->student;

    expect($first->matric_no)->toBe('CSC/2026/0001')
        ->and($second->matric_no)->toBe('CSC/2026/0002')
        ->and(Counter::query()->where('key', 'matric:CSC:2026')->value('value'))->toBe(2);
});

it('omits the department or the year from the counter key when the pattern does too', function () {
    $settings = InstitutionSetting::factory()->create([
        'matric_pattern' => '{YEAR}/{SEQ}',
    ]);
    app(SettingsService::class)->forgetCache();

    $computer = student_programme('CSC');
    $maths = student_programme('MTH');
    student_session();
    $service = app(StudentService::class);
    $actor = student_actor();

    $first = $service->create($actor, student_input($computer, [
        'email' => 'year.one@example.com',
    ]), student_request())->student;
    $second = $service->create($actor, student_input($maths, [
        'email' => 'year.two@example.com',
    ]), student_request())->student;

    expect($first->matric_no)->toBe('2026/1')
        ->and($second->matric_no)->toBe('2026/2')
        ->and(Counter::query()->where('key', 'matric:-:2026')->value('value'))->toBe(2)
        ->and(Counter::query()->count())->toBe(1);

    $settings->matric_pattern = '{DEPT}/{SEQ4}';
    $settings->save();
    app(SettingsService::class)->forgetCache();

    $third = $service->create($actor, student_input($computer, [
        'email' => 'dept.one@example.com',
    ]), student_request())->student;
    $fourth = $service->create($actor, student_input($maths, [
        'email' => 'dept.two@example.com',
    ]), student_request())->student;

    expect($third->matric_no)->toBe('CSC/0001')
        ->and($fourth->matric_no)->toBe('MTH/0001')
        ->and(Counter::query()->where('key', 'matric:CSC:-')->value('value'))->toBe(1)
        ->and(Counter::query()->where('key', 'matric:MTH:-')->value('value'))->toBe(1);
});

it('keeps a sequence past 9999 in full and does not pad {SEQ}', function () {
    student_session();
    $programme = student_programme('CSC');
    Counter::query()->create([
        'key' => 'matric:CSC:2026',
        'value' => 9999,
    ]);

    $padded = app(StudentService::class)->create(
        student_actor(),
        student_input($programme),
        student_request(),
    )->student;

    expect($padded->matric_no)->toBe('CSC/2026/10000');

    InstitutionSetting::factory()->create([
        'matric_pattern' => '{DEPT}/{YEAR}/{SEQ}',
    ]);
    app(SettingsService::class)->forgetCache();

    $plain = app(StudentService::class)->create(
        student_actor(),
        student_input(student_programme('MTH'), [
            'email' => 'seq.plain@example.com',
        ]),
        student_request(),
    )->student;

    expect($plain->matric_no)->toBe('MTH/2026/1');
});

it('does not consume a matric number when creation rolls back', function () {
    $department = Department::factory()->create([
        'code' => str_repeat('A', 28),
    ]);
    $programme = Programme::factory()->create([
        'department_id' => $department->id,
        'duration_years' => 4,
    ]);
    student_session();
    $before_users = User::query()->count();

    $errors = student_field_error(fn () => app(StudentService::class)->create(
        student_actor(),
        student_input($programme, ['email' => 'too.long@example.com']),
        student_request(),
    ), 'programme_id');

    expect($errors['programme_id'][0] ?? null)->toBe(StudentService::MESSAGE_MATRIC_LENGTH)
        ->and(User::query()->where('email', 'too.long@example.com')->exists())->toBeFalse()
        ->and(User::query()->count())->toBe($before_users + 1)
        ->and(Student::query()->count())->toBe(0)
        ->and(Counter::query()->count())->toBe(0);
});

it('refuses a level the programme duration does not allow', function () {
    $programme = student_programme('CSC', 4);
    student_session();
    $service = app(StudentService::class);
    $actor = student_actor();

    $accepted = $service->create($actor, student_input($programme, [
        'email' => 'level.400@example.com',
        'level' => 400,
    ]), student_request())->student;

    $errors = student_field_error(fn () => $service->create($actor, student_input($programme, [
        'email' => 'level.500@example.com',
        'level' => 500,
    ]), student_request()), 'level');

    expect($accepted->level)->toBe(400)
        ->and($errors['level'][0] ?? null)->toBe('This level is not valid for a 4-year programme.')
        ->and(User::query()->where('email', 'level.500@example.com')->exists())->toBeFalse();
});

it('stores the old and new email and only the names of changed personal fields', function () {
    $student = student_make();
    $actor = student_actor();

    $updated = app(StudentService::class)->update($actor, (int) $student->id, student_input($student->programme, [
        'email' => 'ada.changed@example.com',
        'phone' => '08099998888',
        'address' => '40 Broad Street',
        'date_of_birth' => '2005-06-02',
        'level' => 200,
        'other_names' => 'Marie',
    ]), student_request());

    $row = AuditLog::query()->where('action', StudentService::ACTION_UPDATED)->first();
    $encoded = json_encode($row?->before).json_encode($row?->after);
    $changed = is_array($row?->after) ? ($row->after['changed'] ?? []) : [];

    expect($updated->matric_no)->toBe($student->matric_no)
        ->and($updated->user->phone)->toBe('+2348099998888')
        ->and($row?->before['email'] ?? null)->toBe('ada.lovelace@example.com')
        ->and($row?->after['email'] ?? null)->toBe('ada.changed@example.com')
        ->and($row?->before['level'] ?? null)->toBe(100)
        ->and($row?->after['level'] ?? null)->toBe(200)
        ->and($changed)->toContain('phone')
        ->and($changed)->toContain('other_names')
        ->and($changed)->toContain('address')
        ->and($changed)->toContain('date_of_birth')
        ->and(str_contains((string) $encoded, '40 Broad Street'))->toBeFalse()
        ->and(str_contains((string) $encoded, '12 Garden Close'))->toBeFalse()
        ->and(str_contains((string) $encoded, '2005-06-02'))->toBeFalse()
        ->and(str_contains((string) $encoded, '2004-05-01'))->toBeFalse()
        ->and(str_contains((string) $encoded, '+2348099998888'))->toBeFalse()
        ->and(str_contains((string) $encoded, '+2348012345678'))->toBeFalse();
});

it('writes the create audit inside the transaction without personal values', function () {
    $student = student_make();
    $row = AuditLog::query()->where('action', StudentService::ACTION_CREATED)->first();
    $encoded = json_encode($row?->before).json_encode($row?->after);

    expect($row?->entity)->toBe('students')
        ->and($row?->entity_id)->toBe($student->id)
        ->and($row?->ip)->toBe('203.0.113.20')
        ->and($row?->after['email'] ?? null)->toBe('ada.lovelace@example.com')
        ->and($row?->after['matric_no'] ?? null)->toBe('CSC/2026/0001')
        ->and($row?->after['level'] ?? null)->toBe(100)
        ->and($row?->after['changed'] ?? [])->toContain('address')
        ->and($row?->after['changed'] ?? [])->toContain('phone')
        ->and(str_contains((string) $encoded, '12 Garden Close'))->toBeFalse()
        ->and(str_contains((string) $encoded, '+2348012345678'))->toBeFalse()
        ->and(str_contains((string) $encoded, '2004-05-01'))->toBeFalse();
});

it('refuses a faculty admin who tries to create a student', function () {
    $programme = student_programme();
    student_session();

    expect(fn () => app(StudentService::class)->create(
        student_actor(Role::FacultyAdmin),
        student_input($programme),
        student_request(),
    ))->toThrow(AuthorizationException::class);

    expect(Student::query()->count())->toBe(0);
});

it('maps each student status onto one user status', function () {
    $service = app(StudentService::class);

    expect($service->userStatusFor(StudentStatus::Active))->toBe(UserStatus::Active)
        ->and($service->userStatusFor(StudentStatus::Suspended))->toBe(UserStatus::Suspended)
        ->and($service->userStatusFor(StudentStatus::Deferred))->toBe(UserStatus::Deactivated)
        ->and($service->userStatusFor(StudentStatus::Graduated))->toBe(UserStatus::Deactivated)
        ->and($service->userStatusFor(StudentStatus::Withdrawn))->toBe(UserStatus::Deactivated);
});

it('changes status, stores the reason, and revokes sessions in one transaction', function () {
    config(['session.driver' => 'database']);

    $student = student_make();
    $user = $student->user;
    $remember_before = $user->remember_token;
    $actor = student_actor();

    foreach (['session-a', 'session-b'] as $session_id) {
        DB::table('sessions')->insert([
            'id' => $session_id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'browser',
            'payload' => base64_encode('session'),
            'last_activity' => time(),
        ]);
    }

    $suspended = app(StudentService::class)->changeStatus(
        $actor,
        (int) $student->id,
        StudentStatus::Suspended->value,
        'Fees unpaid for the term',
        student_request(),
    );
    $user->refresh();
    $row = AuditLog::query()->where('action', StudentService::ACTION_STATUS_CHANGED)->first();
    $encoded = json_encode($row?->before).json_encode($row?->after);
    $rotated = $user->remember_token !== $remember_before;

    expect($suspended->status)->toBe(StudentStatus::Suspended)
        ->and($user->status)->toBe(UserStatus::Suspended)
        ->and($row?->entity)->toBe('users')
        ->and($row?->after['reason'] ?? null)->toBe('Fees unpaid for the term')
        ->and($row?->before['status'] ?? null)->toBe('Active')
        ->and($row?->after['status'] ?? null)->toBe('Suspended')
        ->and($row?->after['student_status'] ?? null)->toBe('Suspended')
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($rotated)->toBeTrue()
        ->and(str_contains((string) $encoded, '12 Garden Close'))->toBeFalse();

    $graduated = app(StudentService::class)->changeStatus(
        $actor,
        (int) $student->id,
        StudentStatus::Graduated->value,
        'Completed the programme',
        student_request(),
    );

    expect($graduated->status)->toBe(StudentStatus::Graduated)
        ->and($graduated->user->status)->toBe(UserStatus::Deactivated);

    $active = app(StudentService::class)->changeStatus(
        $actor,
        (int) $student->id,
        StudentStatus::Active->value,
        'Returned to the programme',
        student_request(),
    );

    expect($active->status)->toBe(StudentStatus::Active)
        ->and($active->user->status)->toBe(UserStatus::Active)
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('requires a reason before it changes a status', function () {
    $student = student_make();

    $errors = student_field_error(fn () => app(StudentService::class)->changeStatus(
        student_actor(),
        (int) $student->id,
        StudentStatus::Withdrawn->value,
        '   ',
        student_request(),
    ), 'reason');

    expect($errors['reason'][0] ?? null)->toBe(StudentService::MESSAGE_REASON)
        ->and($student->fresh()->status)->toBe(StudentStatus::Active)
        ->and($student->user->fresh()->status)->toBe(UserStatus::Active);
});
