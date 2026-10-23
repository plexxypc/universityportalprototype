<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Enums\ResultStatus;
use App\Enums\Role;
use App\Models\AssessmentComponent;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CoursePrerequisite;
use App\Models\CourseRegistration;
use App\Models\CourseRegistrationItem;
use App\Models\Department;
use App\Models\EmailOutbox;
use App\Models\GradeBand;
use App\Models\GradingScheme;
use App\Models\Notification;
use App\Models\Result;
use App\Models\ResultScore;
use App\Models\RoleAssignment;
use App\Models\Semester;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Services\RegistrationService;
use App\Services\ResultService;
use App\Support\Grading\DefaultScheme;
use App\Support\Grading\Hundredths;
use App\Support\Results\ResultWriteGuard;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\ResultFixture;

uses(RefreshDatabase::class);

/**
 * A database exception with the deadlock code the retry looks for.
 */
function result_workflow_deadlock(): QueryException
{
    $previous = new PDOException('simulated database error');
    $previous->errorInfo = ['40001', 1213, 'simulated'];

    return new QueryException('mysql', 'select 1', [], $previous);
}

/**
 * Save a passing draft for the fixture's only student.
 *
 * @return list<array{result_id: int, student_id: int, updated_at: string}>
 */
function result_workflow_draft(ResultFixture $fixture, ?User $actor = null): array
{
    return app(ResultService::class)->saveDraft(
        $actor ?? $fixture->lecturer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        [result_draft_row((int) $fixture->student->id, $fixture->passingScores(), null)],
        result_draft_request(),
    );
}

it('submits a draft once and refuses a stored total that does not match', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    result_workflow_draft($fixture);
    $service->submit($fixture->lecturer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());
    $service->submit($fixture->lecturer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());
    $result = Result::query()->first();

    expect($result?->status)->toBe(ResultStatus::Submitted)
        ->and($result?->return_reason)->toBeNull()
        ->and(AuditLog::query()->where('action', ResultService::ACTION_SUBMITTED)->count())->toBe(1);

    $result->status = ResultStatus::Approved;
    $result->approved_by = $fixture->examOfficer->id;
    $result->save();
    $audits = AuditLog::query()->count();

    expect(fn () => $service->submit(
        $fixture->lecturer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        result_draft_request(),
    ))->toThrow(ValidationException::class, ResultService::MESSAGE_TRANSITION);

    expect(AuditLog::query()->count())->toBe($audits);
});

it('refuses a stored total that does not match the scores', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    result_workflow_draft($fixture);
    $result = Result::query()->first();
    $result->total = '10.00';
    $result->save();
    $audits = AuditLog::query()->count();

    expect(fn () => $service->submit(
        $fixture->lecturer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        result_draft_request(),
    ))->toThrow(ValidationException::class, ResultService::MESSAGE_DISAGREE);

    expect(AuditLog::query()->count())->toBe($audits)
        ->and(Result::query()->first()?->status)->toBe(ResultStatus::Draft);
});

it('names the student who has no result and writes no submit audit', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    $other = Student::factory()->create([
        'programme_id' => $fixture->programme->id,
        'entry_session_id' => $fixture->session->id,
    ]);
    $registration = CourseRegistration::factory()->approved()->create([
        'student_id' => $other->id,
        'semester_id' => $fixture->semester->id,
        'total_units' => 3,
    ]);
    CourseRegistrationItem::factory()->create([
        'course_registration_id' => $registration->id,
        'course_id' => $fixture->course->id,
        'credit_units' => 3,
    ]);
    result_workflow_draft($fixture);
    $audits = AuditLog::query()->count();

    expect(fn () => $service->submit(
        $fixture->lecturer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        result_draft_request(),
    ))->toThrow(ValidationException::class, (string) $other->id);

    expect(AuditLog::query()->where('action', ResultService::ACTION_SUBMITTED)->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($audits);
});

it('lets an exam officer approve and refuses the super admin who entered it', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    result_workflow_draft($fixture);
    $service->submit($fixture->lecturer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());
    $service->approve($fixture->examOfficer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());

    expect(Result::query()->first()?->status)->toBe(ResultStatus::Approved)
        ->and((int) Result::query()->value('approved_by'))->toBe((int) $fixture->examOfficer->id);
});

it('refuses approval from the super admin who entered the result', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    result_workflow_draft($fixture, $fixture->superAdmin);
    $service->submit($fixture->superAdmin, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());
    $audits = AuditLog::query()->count();

    expect(fn () => $service->approve(
        $fixture->superAdmin,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        result_draft_request(),
    ))->toThrow(ValidationException::class, ResultService::MESSAGE_ENTERER);

    expect(AuditLog::query()->count())->toBe($audits)
        ->and(Result::query()->first()?->status)->toBe(ResultStatus::Submitted);
});

it('hides a course from a department officer outside the student department', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    result_workflow_draft($fixture);
    $service->submit($fixture->lecturer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());
    $service->approve($fixture->departmentOfficer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());

    expect(Result::query()->first()?->status)->toBe(ResultStatus::Approved);
});

it('returns 404 when the department officer is outside the student department', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    result_workflow_draft($fixture);
    $service->submit($fixture->lecturer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());
    $other_department = Department::factory()->create();
    $other = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $other->id,
        'role' => Role::DepartmentOfficer,
        'department_id' => $other_department->id,
    ]);
    $audits = AuditLog::query()->count();

    expect(fn () => $service->approve(
        $other,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        result_draft_request(),
    ))->toThrow(NotFoundHttpException::class);

    expect(AuditLog::query()->count())->toBe($audits);

    expect(fn () => $service->approve(
        $fixture->lecturer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        result_draft_request(),
    ))->toThrow(AuthorizationException::class);
});

it('returns a result with the reason and clears it when the lecturer submits again', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    result_workflow_draft($fixture);
    $service->submit($fixture->lecturer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());
    $service->approve($fixture->examOfficer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());
    $service->returnToDraft(
        $fixture->examOfficer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        'Check the exam',
        result_draft_request(),
    );
    $result = Result::query()->first();
    $audit = AuditLog::query()->where('action', ResultService::ACTION_RETURNED)->first();
    $notice = Notification::query()->where('type', NotificationType::ResultReturned)->first();

    expect($result?->status)->toBe(ResultStatus::Draft)
        ->and($result?->return_reason)->toBe('Check the exam')
        ->and($result?->approved_by)->toBeNull()
        ->and($audit?->after['return_reason'] ?? null)->toBe('Check the exam')
        ->and($notice?->data['message'] ?? null)->toBe('Your results were returned. Check the exam')
        ->and((int) $notice?->user_id)->toBe((int) $fixture->lecturer->id)
        ->and(EmailOutbox::query()->count())->toBe(0);

    $service->submit($fixture->lecturer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());

    expect(Result::query()->first()?->return_reason)->toBeNull()
        ->and(Result::query()->first()?->status)->toBe(ResultStatus::Submitted);
});

it('keeps a long return reason on the row and cuts the notice', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    result_workflow_draft($fixture);
    $service->submit($fixture->lecturer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());
    $reason = str_repeat('a', 500);
    $service->returnToDraft(
        $fixture->examOfficer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        $reason,
        result_draft_request(),
    );
    $notice = Notification::query()->where('type', NotificationType::ResultReturned)->first();
    $message = (string) ($notice?->data['message'] ?? '');

    expect(Result::query()->value('return_reason'))->toBe($reason)
        ->and(AuditLog::query()->where('action', ResultService::ACTION_RETURNED)->first()?->after['return_reason'] ?? null)->toBe($reason)
        ->and(mb_strlen($message))->toBeLessThanOrEqual(240)
        ->and($message)->toStartWith('Your results were returned. ');

    expect(fn () => $service->returnToDraft(
        $fixture->examOfficer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        '  ',
        result_draft_request(),
    ))->toThrow(ValidationException::class, ResultService::MESSAGE_REASON);
});

it('publishes one course and sends one notice and one published email', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    result_workflow_draft($fixture);
    $service->submit($fixture->lecturer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());
    $service->approve($fixture->examOfficer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());
    $service->publish($fixture->examOfficer, (int) $fixture->semester->id, [(int) $fixture->course->id], result_draft_request());
    $result = Result::query()->first();
    $published_at = $result?->published_at?->format('Y-m-d H:i:s');
    $service->publish($fixture->examOfficer, (int) $fixture->semester->id, [(int) $fixture->course->id], result_draft_request());
    $mail = EmailOutbox::query()->first();

    expect($result?->status)->toBe(ResultStatus::Published)
        ->and($published_at)->not->toBeNull()
        ->and($result?->fresh()?->published_at?->format('Y-m-d H:i:s'))->toBe($published_at)
        ->and(Notification::query()->where('type', NotificationType::ResultPublished)->count())->toBe(1)
        ->and(EmailOutbox::query()->count())->toBe(1)
        ->and((string) $mail?->body_text)->toContain('is published')
        ->and((string) $mail?->body_text)->toContain('have been published')
        ->and(AuditLog::query()->where('action', ResultService::ACTION_PUBLISHED)->count())->toBe(1);
});

it('keeps a published course when a later course cannot be published', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    result_workflow_draft($fixture);
    $service->submit($fixture->lecturer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());
    $service->approve($fixture->examOfficer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());
    $other = Course::factory()->create([
        'department_id' => $fixture->department->id,
        'code' => 'CSC202',
        'credit_units' => 3,
    ]);
    CourseRegistrationItem::factory()->create([
        'course_registration_id' => CourseRegistration::query()->value('id'),
        'course_id' => $other->id,
        'credit_units' => 3,
    ]);

    expect(fn () => $service->publish(
        $fixture->examOfficer,
        (int) $fixture->semester->id,
        [(int) $fixture->course->id, (int) $other->id],
        result_draft_request(),
    ))->toThrow(ValidationException::class, ResultService::MESSAGE_MISSING);

    expect(Result::query()->where('course_id', $fixture->course->id)->first()?->status)->toBe(ResultStatus::Published)
        ->and(Notification::query()->where('type', NotificationType::ResultPublished)->count())->toBe(1)
        ->and(EmailOutbox::query()->count())->toBe(1);
});

it('retries one results submit after a deadlock and leaves one audit row', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    result_workflow_draft($fixture);
    $thrown = false;
    $service->deadlockProbe = function () use (&$thrown): void {
        if ($thrown) {
            return;
        }

        $thrown = true;

        throw result_workflow_deadlock();
    };
    $service->submit($fixture->lecturer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());

    expect($thrown)->toBeTrue()
        ->and(Result::query()->first()?->status)->toBe(ResultStatus::Submitted)
        ->and(AuditLog::query()->where('action', ResultService::ACTION_SUBMITTED)->count())->toBe(1);
});

it('refuses a direct change to a published result', function () {
    $result = Result::factory()->published()->create();
    $result->total = '40.00';

    expect(fn () => $result->save())->toThrow(RuntimeException::class, ResultWriteGuard::MESSAGE);

    expect(fn () => ResultScore::factory()->create([
        'result_id' => $result->id,
        'score' => '10.00',
    ]))->toThrow(RuntimeException::class, ResultWriteGuard::MESSAGE);
});

it('publishing a result unlocks the dependent course', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-18 12:00:00', 'Africa/Lagos'));
    $world = registration_rules_world();
    $scheme = result_workflow_scheme();
    $previous = Semester::factory()->create([
        'session_id' => $world['session']->id,
        'name' => 'Earlier',
        'semester_no' => 2,
        'is_active' => false,
    ]);
    CoursePrerequisite::factory()->create([
        'course_id' => $world['elective']->id,
        'prerequisite_course_id' => $world['core']->id,
    ]);
    $lecturer = User::factory()->create();
    RoleAssignment::factory()->create(['user_id' => $lecturer->id, 'role' => Role::Lecturer]);
    $staff = Staff::factory()->create(['user_id' => $lecturer->id]);
    CourseAssignment::factory()->create([
        'staff_id' => $staff->id,
        'course_id' => $world['core']->id,
        'semester_id' => $previous->id,
    ]);
    $registration = CourseRegistration::factory()->approved()->create([
        'student_id' => $world['student']->id,
        'semester_id' => $previous->id,
        'total_units' => 2,
    ]);
    CourseRegistrationItem::factory()->create([
        'course_registration_id' => $registration->id,
        'course_id' => $world['core']->id,
        'credit_units' => 2,
    ]);
    $officer = User::factory()->create();
    RoleAssignment::factory()->create(['user_id' => $officer->id, 'role' => Role::ExamOfficer]);
    $service = app(ResultService::class);
    $service->saveDraft($lecturer, (int) $world['core']->id, (int) $previous->id, [
        result_draft_row((int) $world['student']->id, [
            (int) $scheme['ca'] => '20.00',
            (int) $scheme['exam'] => '50.00',
        ], null),
    ], result_draft_request());
    $now = Carbon::parse('2026-10-18 12:00:00', 'Africa/Lagos');
    $before = collect(app(RegistrationService::class)->assess($world['student'], $now)['courses'])
        ->firstWhere('course_id', $world['elective']->id);

    expect($before['prerequisites_met'] ?? null)->toBeFalse();

    $service->submit($lecturer, (int) $world['core']->id, (int) $previous->id, result_draft_request());
    $service->approve($officer, (int) $world['core']->id, (int) $previous->id, result_draft_request());
    $service->publish($officer, (int) $previous->id, [(int) $world['core']->id], result_draft_request());
    $after = collect(app(RegistrationService::class)->assess($world['student'], $now)['courses'])
        ->firstWhere('course_id', $world['elective']->id);

    expect($after['prerequisites_met'] ?? null)->toBeTrue();

    Carbon::setTestNow();
});

/**
 * An active scheme with the default components.
 *
 * @return array{ca: int, exam: int}
 */
function result_workflow_scheme(): array
{
    $scheme = GradingScheme::factory()->active()->create([
        'name' => DefaultScheme::NAME,
        'pass_mark' => Hundredths::toDecimal(DefaultScheme::passMarkHundredths()),
        'version' => 1,
    ]);
    $ids = ['ca' => 0, 'exam' => 0];

    foreach (DefaultScheme::components() as $component) {
        $row = AssessmentComponent::factory()->create([
            'scheme_id' => $scheme->id,
            'name' => $component['name'],
            'max_score' => Hundredths::toDecimal($component['max_hundredths']),
            'sort' => $component['sort'],
        ]);

        if ($component['name'] === 'Continuous Assessment') {
            $ids['ca'] = (int) $row->id;
        }

        if ($component['name'] === 'Exam') {
            $ids['exam'] = (int) $row->id;
        }
    }

    foreach (DefaultScheme::bands() as $band) {
        GradeBand::factory()->create([
            'scheme_id' => $scheme->id,
            'min_score' => Hundredths::toDecimal($band['min_hundredths']),
            'max_score' => Hundredths::toDecimal($band['max_hundredths']),
            'letter' => $band['letter'],
            'points' => Hundredths::toDecimal($band['points_hundredths']),
            'remark' => $band['remark'],
        ]);
    }

    return $ids;
}
