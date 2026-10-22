<?php

declare(strict_types=1);

use App\Enums\CourseType;
use App\Enums\ResultStatus;
use App\Enums\StudentStatus;
use App\Models\Course;
use App\Models\CoursePrerequisite;
use App\Models\CourseRegistration;
use App\Models\CourseRegistrationItem;
use App\Models\GradingScheme;
use App\Models\InstitutionSetting;
use App\Models\ProgrammeCourse;
use App\Models\Result;
use App\Models\Semester;
use App\Services\RegistrationService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('offers core and elective courses for the programme level and semester number', function () {
    $world = registration_rules_world();
    $other = Course::factory()->create([
        'department_id' => $world['programme']->department_id,
        'code' => 'CSC201',
        'credit_units' => 2,
    ]);
    ProgrammeCourse::factory()->create([
        'programme_id' => $world['programme']->id,
        'course_id' => $other->id,
        'level' => 200,
        'semester_no' => 1,
        'type' => CourseType::Core,
    ]);
    $later = Course::factory()->create([
        'department_id' => $world['programme']->department_id,
        'code' => 'CSC199',
        'credit_units' => 2,
    ]);
    ProgrammeCourse::factory()->elective()->create([
        'programme_id' => $world['programme']->id,
        'course_id' => $later->id,
        'level' => 100,
        'semester_no' => 2,
    ]);

    $assessment = app(RegistrationService::class)->assess(
        $world['student'],
        Carbon::parse('2026-10-18 12:00:00', 'Africa/Lagos'),
    );
    $ids = array_column($assessment['courses'], 'course_id');
    $core = collect($assessment['courses'])->firstWhere('course_id', $world['core']->id);
    $elective = collect($assessment['courses'])->firstWhere('course_id', $world['elective']->id);

    expect($assessment['state'])->toBe('open')
        ->and($ids)->toBe([$world['core']->id, $world['elective']->id])
        ->and($core['locked'] ?? null)->toBeTrue()
        ->and($elective['locked'] ?? null)->toBeFalse()
        ->and($assessment['registration_closes_at'])->toContain('closes at')
        ->and(str_contains((string) $assessment['registration_closes_at'], 'until'))->toBeFalse();
});

it('refuses a student who is not active', function (StudentStatus $status) {
    $world = registration_rules_world();
    $world['student']->status = $status;
    $world['student']->save();

    $assessment = app(RegistrationService::class)->assess($world['student']);

    expect($assessment['state'])->toBe('not_active')
        ->and($assessment['message'])->toBe(RegistrationService::MESSAGE_NOT_ACTIVE)
        ->and($assessment['courses'])->toBe([])
        ->and($assessment['can_start'])->toBeFalse();
})->with([
    StudentStatus::Suspended,
    StudentStatus::Deferred,
    StudentStatus::Graduated,
    StudentStatus::Withdrawn,
]);

it('blocks a selection outside the unit limits only when limits are enforced', function () {
    $world = registration_rules_world();
    registration_rules_limits(5, 6);
    $now = Carbon::parse('2026-10-18 12:00:00', 'Africa/Lagos');
    $service = app(RegistrationService::class);

    $draft = $service->resolveSelection($world['student'], [$world['elective']->id], false, $now);

    expect(array_column($draft, 'course_id'))->toBe([$world['core']->id, $world['elective']->id])
        ->and(array_sum(array_column($draft, 'credit_units')))->toBe(4);

    expect(fn () => $service->resolveSelection($world['student'], [], true, $now))
        ->toThrow(ValidationException::class, RegistrationService::MESSAGE_BELOW_MIN);

    registration_rules_limits(2, 2);

    expect($service->resolveSelection($world['student'], [], true, $now))->toHaveCount(1);

    expect(fn () => $service->resolveSelection($world['student'], [$world['elective']->id], true, $now))
        ->toThrow(ValidationException::class, RegistrationService::MESSAGE_ABOVE_MAX);
});

it('blocks a selection when compulsory courses exceed the maximum', function () {
    $world = registration_rules_world();
    $world['core']->credit_units = 3;
    $world['core']->save();
    registration_rules_limits(1, 2);
    $now = Carbon::parse('2026-10-18 12:00:00', 'Africa/Lagos');

    $assessment = app(RegistrationService::class)->assess($world['student'], $now);

    expect($assessment['core_exceeds_maximum'])->toBeTrue()
        ->and($assessment['core_units'])->toBe(3);

    expect(fn () => app(RegistrationService::class)->resolveSelection($world['student'], [], true, $now))
        ->toThrow(ValidationException::class, RegistrationService::MESSAGE_CORE_OVER_MAX);

    expect(app(RegistrationService::class)->resolveSelection($world['student'], [], false, $now))->toHaveCount(1);
});

it('accepts a published pass and ignores a same-semester result', function () {
    $world = registration_rules_world();
    $scheme = GradingScheme::factory()->create(['pass_mark' => '40.00', 'version' => 1]);
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
    $now = Carbon::parse('2026-10-18 12:00:00', 'Africa/Lagos');
    $service = app(RegistrationService::class);

    $blocked = collect($service->assess($world['student'], $now)['courses'])
        ->firstWhere('course_id', $world['elective']->id);
    expect($blocked['prerequisites_met'] ?? null)->toBeFalse();

    expect(fn () => $service->resolveSelection($world['student'], [$world['elective']->id], false, $now))
        ->toThrow(ValidationException::class, RegistrationService::MESSAGE_PREREQUISITE);

    CourseRegistrationItem::factory()->create([
        'course_registration_id' => CourseRegistration::factory()->create([
            'student_id' => $world['student']->id,
            'semester_id' => $world['semester']->id,
        ])->id,
        'course_id' => $world['core']->id,
        'credit_units' => 2,
    ]);

    $still_blocked = collect($service->assess($world['student'], $now)['courses'])
        ->firstWhere('course_id', $world['elective']->id);
    expect($still_blocked['prerequisites_met'] ?? null)->toBeFalse();

    Result::factory()->published()->create([
        'student_id' => $world['student']->id,
        'course_id' => $world['core']->id,
        'semester_id' => $world['semester']->id,
        'grading_scheme_id' => $scheme->id,
        'scheme_version' => 1,
        'total' => '70.00',
    ]);

    $same_semester = collect($service->assess($world['student'], $now)['courses'])
        ->firstWhere('course_id', $world['elective']->id);
    expect($same_semester['prerequisites_met'] ?? null)->toBeFalse();

    $previous_result = Result::factory()->approved()->create([
        'student_id' => $world['student']->id,
        'course_id' => $world['core']->id,
        'semester_id' => $previous->id,
        'grading_scheme_id' => $scheme->id,
        'scheme_version' => 1,
        'total' => '70.00',
    ]);

    $approved_only = collect($service->assess($world['student'], $now)['courses'])
        ->firstWhere('course_id', $world['elective']->id);
    expect($approved_only['prerequisites_met'] ?? null)->toBeFalse();

    $previous_result->status = ResultStatus::Published;
    $previous_result->published_at = Carbon::parse('2026-01-01 00:00:00', 'Africa/Lagos');
    $previous_result->total = '39.99';
    $previous_result->save();

    $below = collect($service->assess($world['student'], $now)['courses'])
        ->firstWhere('course_id', $world['elective']->id);
    expect($below['prerequisites_met'] ?? null)->toBeFalse();

    $previous_result->total = '40.00';
    $previous_result->save();

    $passed = collect($service->assess($world['student'], $now)['courses'])
        ->firstWhere('course_id', $world['elective']->id);
    expect($passed['prerequisites_met'] ?? null)->toBeTrue();

    $outside = Course::factory()->create(['code' => 'MTH101', 'credit_units' => 2]);

    expect(fn () => $service->resolveSelection($world['student'], [$outside->id], false, $now))
        ->toThrow(ValidationException::class, RegistrationService::MESSAGE_NOT_ELIGIBLE);
});

it('locks registration at the deadline second', function () {
    $world = registration_rules_world();
    $world['semester']->registration_deadline = '2026-10-18 12:00:00';
    $world['semester']->add_drop_deadline = '2026-10-18 18:00:00';
    $world['semester']->save();
    $service = app(RegistrationService::class);
    $before = Carbon::parse('2026-10-18 11:59:59', 'Africa/Lagos');
    $deadline = Carbon::parse('2026-10-18 12:00:00', 'Africa/Lagos');
    $during = Carbon::parse('2026-10-18 17:59:59', 'Africa/Lagos');
    $end = Carbon::parse('2026-10-18 18:00:00', 'Africa/Lagos');

    expect($service->window($world['semester'], null, $before)['registration_open'])->toBeTrue()
        ->and($service->window($world['semester'], null, $before)['add_drop_open'])->toBeFalse()
        ->and($service->assess($world['student'], $before)['can_start'])->toBeTrue();

    expect($service->window($world['semester'], null, $deadline)['registration_open'])->toBeFalse()
        ->and($service->window($world['semester'], null, $deadline)['add_drop_open'])->toBeTrue()
        ->and($service->assess($world['student'], $deadline)['can_start'])->toBeFalse()
        ->and($service->assess($world['student'], $deadline)['can_add_drop'])->toBeTrue()
        ->and($service->assess($world['student'], $deadline)['state'])->toBe('add_drop');

    expect($service->window($world['semester'], null, $during)['add_drop_open'])->toBeTrue()
        ->and($service->window($world['semester'], null, $end)['add_drop_open'])->toBeFalse()
        ->and($service->assess($world['student'], $end)['can_add_drop'])->toBeFalse()
        ->and($service->assess($world['student'], $end)['state'])->toBe('closed');

    $reopen = Carbon::parse('2026-10-18 18:00:00', 'Africa/Lagos');
    CourseRegistration::factory()->create([
        'student_id' => $world['student']->id,
        'semester_id' => $world['semester']->id,
        'reopened_until' => $reopen,
    ]);

    expect($service->window($world['semester']->fresh(), $reopen, $during)['reopened'])->toBeTrue()
        ->and($service->window($world['semester']->fresh(), $reopen, $end)['reopened'])->toBeFalse()
        ->and($service->assess($world['student']->fresh(), $during)['can_start'])->toBeTrue()
        ->and($service->assess($world['student']->fresh(), $end)['can_start'])->toBeFalse();
});

it('keeps registration closed when the deadline is missing', function () {
    $world = registration_rules_world();
    $world['semester']->registration_deadline = null;
    $world['semester']->add_drop_deadline = null;
    $world['semester']->save();

    $assessment = app(RegistrationService::class)->assess($world['student']);

    expect($assessment['state'])->toBe('closed')
        ->and($assessment['message'])->toBe(RegistrationService::MESSAGE_NO_DEADLINE)
        ->and($assessment['can_start'])->toBeFalse()
        ->and($assessment['can_add_drop'])->toBeFalse();
});

it('opens add and drop from the deadline second and closes it at the add-drop second', function () {
    $world = registration_rules_world();
    $world['semester']->registration_deadline = '2026-10-18 12:00:00';
    $world['semester']->add_drop_deadline = null;
    $world['semester']->save();
    $deadline = Carbon::parse('2026-10-18 12:00:00', 'Africa/Lagos');

    $window = app(RegistrationService::class)->window($world['semester'], null, $deadline);

    expect($window['registration_open'])->toBeFalse()
        ->and($window['add_drop_open'])->toBeFalse();
});

it('refuses registration when the active semester has no semester number', function () {
    $world = registration_rules_world();
    $world['semester']->semester_no = null;
    $world['semester']->save();

    $assessment = app(RegistrationService::class)->assess($world['student']);

    expect($assessment['state'])->toBe('no_semester_no')
        ->and($assessment['message'])->toBe(RegistrationService::MESSAGE_NO_SEMESTER_NO)
        ->and($assessment['can_start'])->toBeFalse();
});

it('does not read invoices when minimum payment is switched on', function () {
    $world = registration_rules_world();
    InstitutionSetting::factory()->create(['require_minimum_payment' => true]);
    app(SettingsService::class)->forgetCache();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $satisfied = app(RegistrationService::class)->minimumPaymentSatisfied($world['student']);
    $sql = implode(' ', array_column(DB::getQueryLog(), 'query'));

    expect($satisfied)->toBeFalse()
        ->and(str_contains(strtolower($sql), 'invoices'))->toBeFalse()
        ->and(str_contains(strtolower($sql), 'payments'))->toBeFalse();

    $assessment = app(RegistrationService::class)->assess(
        $world['student'],
        Carbon::parse('2026-10-18 12:00:00', 'Africa/Lagos'),
    );

    expect($assessment['state'])->toBe('payment_blocked')
        ->and($assessment['message'])->toBe(RegistrationService::MESSAGE_PAYMENT)
        ->and($assessment['can_start'])->toBeFalse();
});
