<?php

declare(strict_types=1);

use App\Enums\RegistrationStatus;
use App\Enums\ResultStatus;
use App\Enums\Role;
use App\Models\AssessmentComponent;
use App\Models\AuditLog;
use App\Models\CourseAssignment;
use App\Models\CourseRegistration;
use App\Models\CourseRegistrationItem;
use App\Models\GradingScheme;
use App\Models\Result;
use App\Models\ResultScore;
use App\Models\RoleAssignment;
use App\Models\Semester;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Services\RegistrationService;
use App\Services\ResultService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Support\ResultFixture;

uses(RefreshDatabase::class);

/**
 * A request with a fixed audit address.
 */
function result_draft_request(): Request
{
    return Request::create('/staff/results', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.40',
    ]);
}

/**
 * @param  array<int, string|null>  $scores
 * @return array{student_id: int, scores: array<int, string|null>, version: ?string}
 */
function result_draft_row(int $student_id, array $scores, ?string $version): array
{
    return [
        'student_id' => $student_id,
        'scores' => $scores,
        'version' => $version,
    ];
}

it('stores a recomputed total and refuses a client total', function () {
    $fixture = ResultFixture::make();
    $saved = app(ResultService::class)->saveDraft(
        $fixture->lecturer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        [result_draft_row((int) $fixture->student->id, $fixture->passingScores(), null)],
        result_draft_request(),
    );
    $result = Result::query()->first();

    expect($saved)->toHaveCount(1)
        ->and($result)->not->toBeNull()
        ->and((string) $result?->total)->toBe('70.00')
        ->and($result?->grade)->toBe('A')
        ->and((string) $result?->points)->toBe('5.00')
        ->and($result?->status)->toBe(ResultStatus::Draft)
        ->and((int) $result?->grading_scheme_id)->toBe((int) $fixture->scheme->id)
        ->and((int) $result?->scheme_version)->toBe((int) $fixture->scheme->version)
        ->and((int) $result?->entered_by)->toBe((int) $fixture->lecturer->id);

    $audit = AuditLog::query()->where('action', ResultService::ACTION_ENTERED)->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->actor_id)->toBe($fixture->lecturer->id)
        ->and($audit?->entity)->toBe('results')
        ->and($audit?->ip)->toBe('203.0.113.40')
        ->and($audit?->after['total'] ?? null)->toBe('70.00')
        ->and(array_key_exists('matric_no', $audit?->after ?? []))->toBeFalse();

    expect(fn () => app(ResultService::class)->saveDraft(
        $fixture->lecturer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        [[
            'student_id' => $fixture->student->id,
            'scores' => $fixture->passingScores(),
            'version' => $saved[0]['updated_at'],
            'total' => '99.00',
        ]],
        result_draft_request(),
    ))->toThrow(ValidationException::class, ResultService::MESSAGE_CLIENT_TOTAL);
});

it('allows a blank draft and stores zero as a score', function () {
    $fixture = ResultFixture::make();
    app(ResultService::class)->saveDraft(
        $fixture->lecturer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        [result_draft_row((int) $fixture->student->id, [
            (int) $fixture->ca->id => null,
            (int) $fixture->exam->id => '0',
        ], null)],
        result_draft_request(),
    );
    $result = Result::query()->first();

    expect($result?->total)->toBeNull()
        ->and($result?->grade)->toBeNull()
        ->and($result?->points)->toBeNull()
        ->and(ResultScore::query()->count())->toBe(1)
        ->and((string) ResultScore::query()->first()?->score)->toBe('0.00');
});

it('refuses a score above the maximum, a foreign component, and a third decimal', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    $course_id = (int) $fixture->course->id;
    $semester_id = (int) $fixture->semester->id;
    $student_id = (int) $fixture->student->id;
    $other = AssessmentComponent::factory()->create([
        'scheme_id' => GradingScheme::factory()->create(['version' => 9])->id,
        'max_score' => '10.00',
    ]);

    expect(fn () => $service->saveDraft($fixture->lecturer, $course_id, $semester_id, [
        result_draft_row($student_id, [(int) $fixture->ca->id => '30.01', (int) $fixture->exam->id => '50.00'], null),
    ], result_draft_request()))->toThrow(ValidationException::class, ResultService::MESSAGE_SCORE);

    expect(fn () => $service->saveDraft($fixture->lecturer, $course_id, $semester_id, [
        result_draft_row($student_id, [(int) $other->id => '1.00'], null),
    ], result_draft_request()))->toThrow(ValidationException::class, ResultService::MESSAGE_COMPONENT);

    expect(fn () => $service->saveDraft($fixture->lecturer, $course_id, $semester_id, [
        result_draft_row($student_id, [(int) $fixture->ca->id => '1.001'], null),
    ], result_draft_request()))->toThrow(ValidationException::class, ResultService::MESSAGE_SCORE);

    expect(Result::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('refuses a student who is not approved and a lecturer assigned to another semester', function () {
    $fixture = ResultFixture::make();
    $other = Student::factory()->create([
        'programme_id' => $fixture->programme->id,
        'entry_session_id' => $fixture->session->id,
    ]);
    $registration = CourseRegistration::factory()->create([
        'student_id' => $other->id,
        'semester_id' => $fixture->semester->id,
        'status' => RegistrationStatus::Submitted,
    ]);
    CourseRegistrationItem::factory()->create([
        'course_registration_id' => $registration->id,
        'course_id' => $fixture->course->id,
        'credit_units' => 3,
    ]);
    $later = Semester::factory()->create([
        'session_id' => $fixture->session->id,
        'semester_no' => 2,
        'is_active' => false,
    ]);
    $other_lecturer = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $other_lecturer->id,
        'role' => Role::Lecturer,
    ]);
    $staff = Staff::factory()->create(['user_id' => $other_lecturer->id]);
    CourseAssignment::factory()->create([
        'staff_id' => $staff->id,
        'course_id' => $fixture->course->id,
        'semester_id' => $later->id,
    ]);

    expect(fn () => app(ResultService::class)->saveDraft(
        $fixture->lecturer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        [result_draft_row((int) $other->id, $fixture->passingScores(), null)],
        result_draft_request(),
    ))->toThrow(ValidationException::class, ResultService::MESSAGE_STUDENT);

    expect(fn () => app(ResultService::class)->saveDraft(
        $other_lecturer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        [result_draft_row((int) $fixture->student->id, $fixture->passingScores(), null)],
        result_draft_request(),
    ))->toThrow(AuthorizationException::class);

    expect(Result::query()->count())->toBe(0);
});

it('lets a super admin enter on a semester that is not active', function () {
    $fixture = ResultFixture::make();

    expect($fixture->semester->is_active)->toBeFalse();

    app(ResultService::class)->saveDraft(
        $fixture->superAdmin,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        [result_draft_row((int) $fixture->student->id, $fixture->passingScores(), null)],
        result_draft_request(),
    );

    expect(Result::query()->count())->toBe(1)
        ->and((int) Result::query()->value('entered_by'))->toBe((int) $fixture->superAdmin->id);
});

it('writes nothing when the draft is saved again unchanged', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    $first = $service->saveDraft(
        $fixture->lecturer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        [result_draft_row((int) $fixture->student->id, $fixture->passingScores(), null)],
        result_draft_request(),
    );
    $service->saveDraft(
        $fixture->lecturer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        [result_draft_row((int) $fixture->student->id, $fixture->passingScores(), $first[0]['updated_at'])],
        result_draft_request(),
    );

    expect(AuditLog::query()->count())->toBe(1)
        ->and(Result::query()->first()?->updated_at?->format('Y-m-d H:i:s'))->toBe($first[0]['updated_at']);
});

it('rejects a stale version token and writes no audit row', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    $saved = $service->saveDraft(
        $fixture->lecturer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        [result_draft_row((int) $fixture->student->id, $fixture->passingScores(), null)],
        result_draft_request(),
    );
    $before = AuditLog::query()->count();

    expect(fn () => $service->saveDraft(
        $fixture->lecturer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        [result_draft_row((int) $fixture->student->id, [
            (int) $fixture->ca->id => '10.00',
            (int) $fixture->exam->id => '50.00',
        ], '2000-01-01 00:00:00')],
        result_draft_request(),
    ))->toThrow(ValidationException::class, ResultService::MESSAGE_VERSION);

    expect(AuditLog::query()->count())->toBe($before)
        ->and((string) Result::query()->first()?->total)->toBe('70.00')
        ->and($saved[0]['updated_at'])->not->toBe('');
});

it('refuses to drop a course that already has a result', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-02 12:00:00', 'Africa/Lagos'));
    $world = registration_rules_world();
    registration_rules_limits(1, 24);
    $user = $world['student']->user;
    expect($user)->toBeInstanceOf(User::class);
    $registration = CourseRegistration::factory()->approved()->create([
        'student_id' => $world['student']->id,
        'semester_id' => $world['semester']->id,
        'total_units' => 4,
    ]);
    CourseRegistrationItem::factory()->create([
        'course_registration_id' => $registration->id,
        'course_id' => $world['core']->id,
        'credit_units' => 2,
    ]);
    CourseRegistrationItem::factory()->create([
        'course_registration_id' => $registration->id,
        'course_id' => $world['elective']->id,
        'credit_units' => 2,
    ]);
    Result::factory()->create([
        'student_id' => $world['student']->id,
        'course_id' => $world['elective']->id,
        'semester_id' => $world['semester']->id,
        'grading_scheme_id' => GradingScheme::factory()->create(['version' => 4])->id,
        'scheme_version' => 4,
        'entered_by' => User::factory(),
    ]);
    $audits = AuditLog::query()->count();

    expect(fn () => app(RegistrationService::class)->addDrop(
        $user,
        [],
        result_draft_request(),
    ))->toThrow(ValidationException::class, RegistrationService::MESSAGE_RESULT_HELD);

    expect($registration->fresh()?->items()->count())->toBe(2)
        ->and(AuditLog::query()->count())->toBe($audits);

    Carbon::setTestNow();
});

it('leaves a course with a result in place when registration is reopened', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-18 12:00:00', 'Africa/Lagos'));
    $world = registration_rules_world();
    registration_rules_limits(1, 24);
    $shell_user = $world['student']->user;
    expect($shell_user)->toBeInstanceOf(User::class);
    $registration = CourseRegistration::factory()->approved()->create([
        'student_id' => $world['student']->id,
        'semester_id' => $world['semester']->id,
        'total_units' => 4,
    ]);
    CourseRegistrationItem::factory()->create([
        'course_registration_id' => $registration->id,
        'course_id' => $world['core']->id,
        'credit_units' => 2,
    ]);
    CourseRegistrationItem::factory()->create([
        'course_registration_id' => $registration->id,
        'course_id' => $world['elective']->id,
        'credit_units' => 2,
    ]);
    Result::factory()->create([
        'student_id' => $world['student']->id,
        'course_id' => $world['elective']->id,
        'semester_id' => $world['semester']->id,
        'grading_scheme_id' => GradingScheme::factory()->create(['version' => 5])->id,
        'scheme_version' => 5,
        'entered_by' => User::factory(),
    ]);
    $registrar = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $registrar->id,
        'role' => Role::Registrar,
    ]);
    $world['semester']->registration_deadline = '2026-10-18 09:00:00';
    $world['semester']->add_drop_deadline = '2026-10-18 09:00:00';
    $world['semester']->save();

    $opened = app(RegistrationService::class)->reopen(
        $registrar,
        (int) $world['student']->id,
        'Late return',
        Carbon::parse('2026-10-20 12:00:00', 'Africa/Lagos'),
        result_draft_request(),
    );

    expect($opened->items()->pluck('course_id')->all())->toContain((int) $world['core']->id)
        ->and($opened->items()->pluck('course_id')->all())->toContain((int) $world['elective']->id);

    expect(fn () => app(RegistrationService::class)->addDrop(
        $shell_user,
        [],
        result_draft_request(),
    ))->toThrow(ValidationException::class, RegistrationService::MESSAGE_RESULT_HELD);

    Carbon::setTestNow();
});
