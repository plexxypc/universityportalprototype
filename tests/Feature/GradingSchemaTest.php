<?php

declare(strict_types=1);

use App\Enums\RepeatPolicy;
use App\Enums\ResultStatus;
use App\Models\AssessmentComponent;
use App\Models\GradeBand;
use App\Models\GradingScheme;
use App\Models\Result;
use App\Models\ResultScore;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('rejects a second active grading scheme', function () {
    GradingScheme::factory()->create(['is_active' => true]);

    expect(fn () => GradingScheme::factory()->create(['is_active' => true]))
        ->toThrow(QueryException::class);
});

it('allows another grading scheme when it is not active', function () {
    GradingScheme::factory()->create(['is_active' => true]);
    GradingScheme::factory()->create(['is_active' => false]);

    expect(DB::table('grading_schemes')->count())->toBe(2);
});

it('generates the active flag from is_active and leaves it out of fillable', function () {
    expect((new GradingScheme)->getFillable())->not->toContain('active_flag');

    $scheme = GradingScheme::query()->create([
        'name' => 'Default',
        'pass_mark' => '40.00',
        'version' => 1,
        'is_active' => true,
        'repeat_policy' => RepeatPolicy::Latest,
        'active_flag' => null,
    ]);
    $scheme->refresh();

    expect((int) $scheme->active_flag)->toBe(1);

    $other = GradingScheme::query()->create([
        'name' => 'Previous',
        'pass_mark' => '40.00',
        'version' => 2,
        'is_active' => false,
        'repeat_policy' => RepeatPolicy::Best,
        'active_flag' => 1,
    ]);
    $other->refresh();

    expect($other->active_flag)->toBeNull();
});

it('rejects a second letter on the same scheme', function () {
    $band = GradeBand::factory()->create(['letter' => 'A']);

    expect(fn () => GradeBand::factory()->create([
        'scheme_id' => $band->scheme_id,
        'letter' => 'A',
        'min_score' => '60.00',
        'max_score' => '69.00',
    ]))->toThrow(QueryException::class);
});

it('allows the same letter on another scheme', function () {
    GradeBand::factory()->create(['letter' => 'A']);
    GradeBand::factory()->create(['letter' => 'A']);

    expect(DB::table('grade_bands')->count())->toBe(2);
});

it('rejects a grade band whose minimum is above its maximum', function () {
    expect(fn () => GradeBand::factory()->create([
        'min_score' => '50.00',
        'max_score' => '40.00',
    ]))->toThrow(QueryException::class);
});

it('rejects a grade band above 100', function () {
    expect(fn () => GradeBand::factory()->create([
        'min_score' => '90.00',
        'max_score' => '101.00',
    ]))->toThrow(QueryException::class);
});

it('rejects a negative grade point', function () {
    expect(fn () => GradeBand::factory()->create(['points' => '-1.00']))
        ->toThrow(QueryException::class);
});

it('rejects a pass mark outside 0 to 100', function () {
    expect(fn () => GradingScheme::factory()->create(['pass_mark' => '-0.01']))
        ->toThrow(QueryException::class);

    expect(fn () => GradingScheme::factory()->create(['pass_mark' => '100.01']))
        ->toThrow(QueryException::class);
});

it('rejects a negative resit points cap', function () {
    expect(fn () => GradingScheme::factory()->create(['resit_points_cap' => '-1.00']))
        ->toThrow(QueryException::class);
});

it('rejects a component maximum of zero', function () {
    expect(fn () => AssessmentComponent::factory()->create(['max_score' => '0.00']))
        ->toThrow(QueryException::class);
});

it('rejects a second component with the same sort on one scheme', function () {
    $component = AssessmentComponent::factory()->create(['sort' => 1]);

    expect(fn () => AssessmentComponent::factory()->create([
        'scheme_id' => $component->scheme_id,
        'name' => 'Exam',
        'sort' => 1,
    ]))->toThrow(QueryException::class);
});

it('rejects a duplicate result for the same student, course, and semester', function () {
    $result = Result::factory()->create();

    expect(fn () => Result::factory()->create([
        'student_id' => $result->student_id,
        'course_id' => $result->course_id,
        'semester_id' => $result->semester_id,
    ]))->toThrow(QueryException::class);
});

it('stores a repeated course in a different semester', function () {
    $result = Result::factory()->create();

    Result::factory()->create([
        'student_id' => $result->student_id,
        'course_id' => $result->course_id,
        'semester_id' => Semester::factory(),
    ]);

    expect(DB::table('results')->count())->toBe(2);
});

it('rejects a result unless total, grade, and points are all null or all set', function () {
    expect(fn () => Result::factory()->create([
        'total' => '70.00',
        'grade' => null,
        'points' => null,
    ]))->toThrow(QueryException::class);

    expect(fn () => Result::factory()->create([
        'total' => '70.00',
        'grade' => 'A',
        'points' => null,
    ]))->toThrow(QueryException::class);

    expect(fn () => Result::factory()->create([
        'total' => null,
        'grade' => 'A',
        'points' => null,
    ]))->toThrow(QueryException::class);

    expect(fn () => Result::factory()->create([
        'total' => null,
        'grade' => null,
        'points' => '5.00',
    ]))->toThrow(QueryException::class);
});

it('stores a result when total, grade, and points are all null', function () {
    $result = Result::factory()->create([
        'total' => null,
        'grade' => null,
        'points' => null,
    ]);

    expect($result->exists)->toBeTrue()
        ->and($result->total)->toBeNull()
        ->and($result->grade)->toBeNull()
        ->and($result->points)->toBeNull();
});

it('stores a result when total, grade, and points are all set', function () {
    $result = Result::factory()->create([
        'total' => '70.00',
        'grade' => 'A',
        'points' => '5.00',
    ]);

    expect($result->exists)->toBeTrue()
        ->and($result->total)->toBe('70.00')
        ->and($result->grade)->toBe('A')
        ->and($result->points)->toBe('5.00');
});

it('rejects a negative result total', function () {
    expect(fn () => Result::factory()->create([
        'total' => '-1.00',
        'grade' => 'F',
        'points' => '0.00',
    ]))->toThrow(QueryException::class);
});

it('rejects a negative result point value', function () {
    expect(fn () => Result::factory()->create([
        'total' => '10.00',
        'grade' => 'F',
        'points' => '-1.00',
    ]))->toThrow(QueryException::class);
});

it('rejects an approved or published result with no approver', function () {
    expect(fn () => Result::factory()->create([
        'status' => ResultStatus::Approved,
        'approved_by' => null,
        'total' => '70.00',
        'grade' => 'A',
        'points' => '5.00',
    ]))->toThrow(QueryException::class);

    expect(fn () => Result::factory()->create([
        'status' => ResultStatus::Published,
        'approved_by' => null,
        'published_at' => '2026-10-09 12:00:00',
        'total' => '70.00',
        'grade' => 'A',
        'points' => '5.00',
    ]))->toThrow(QueryException::class);
});

it('stores an approved result when approved_by is set', function () {
    $result = Result::factory()->create([
        'status' => ResultStatus::Approved,
        'approved_by' => User::factory(),
        'published_at' => null,
        'total' => '70.00',
        'grade' => 'A',
        'points' => '5.00',
    ]);

    expect($result->exists)->toBeTrue()
        ->and($result->status)->toBe(ResultStatus::Approved)
        ->and($result->published_at)->toBeNull();
});

it('rejects published_at unless the status is published', function () {
    expect(fn () => Result::factory()->create([
        'status' => ResultStatus::Draft,
        'published_at' => '2026-10-09 12:00:00',
    ]))->toThrow(QueryException::class);

    expect(fn () => Result::factory()->create([
        'status' => ResultStatus::Approved,
        'approved_by' => User::factory(),
        'published_at' => '2026-10-09 12:00:00',
        'total' => '70.00',
        'grade' => 'A',
        'points' => '5.00',
    ]))->toThrow(QueryException::class);
});

it('stores published_at on a published result', function () {
    $result = Result::factory()->create([
        'status' => ResultStatus::Published,
        'approved_by' => User::factory(),
        'published_at' => '2026-10-09 12:00:00',
        'total' => '70.00',
        'grade' => 'A',
        'points' => '5.00',
    ]);

    expect($result->exists)->toBeTrue()
        ->and($result->published_at?->format('Y-m-d H:i:s'))->toBe('2026-10-09 12:00:00');
});

it('rejects a published result with no published_at', function () {
    expect(fn () => Result::factory()->create([
        'status' => ResultStatus::Published,
        'approved_by' => User::factory(),
        'published_at' => null,
        'total' => null,
        'grade' => null,
        'points' => null,
    ]))->toThrow(QueryException::class);
});

it('rejects an unknown result status', function () {
    $result = Result::factory()->create();

    expect(fn () => DB::table('results')->where('id', $result->id)->update([
        'status' => 'Nope',
    ]))->toThrow(QueryException::class);
});

it('rejects a second score for the same result and component', function () {
    $score = ResultScore::factory()->create();

    expect(fn () => ResultScore::factory()->create([
        'result_id' => $score->result_id,
        'component_id' => $score->component_id,
    ]))->toThrow(QueryException::class);
});

it('rejects a negative result score', function () {
    expect(fn () => ResultScore::factory()->create(['score' => '-1.00']))
        ->toThrow(QueryException::class);
});

it('stores a zero result score', function () {
    $score = ResultScore::factory()->create(['score' => '0.00']);

    expect($score->exists)->toBeTrue()
        ->and($score->score)->toBe('0.00');
});

it('blocks deleting a result that has a score', function () {
    $score = ResultScore::factory()->create();

    expect(fn () => DB::table('results')->where('id', $score->result_id)->delete())
        ->toThrow(QueryException::class);
});

it('restricts every grading and result foreign key', function () {
    $rows = DB::select(
        'select CONSTRAINT_NAME as constraint_name, DELETE_RULE as delete_rule
         from information_schema.referential_constraints
         where CONSTRAINT_SCHEMA = ? and TABLE_NAME in (?, ?, ?, ?, ?)',
        [
            DB::getDatabaseName(),
            'assessment_components',
            'grade_bands',
            'classification_bands',
            'results',
            'result_scores',
        ],
    );

    $delete_rules = collect($rows)->mapWithKeys(
        fn (object $row): array => [$row->constraint_name => $row->delete_rule],
    );

    expect($delete_rules)->toHaveCount(11)
        ->and($delete_rules->every(fn (string $rule): bool => $rule === 'RESTRICT'))->toBeTrue();
});

it('has the grading and result tables', function () {
    expect(Schema::hasTable('grading_schemes'))->toBeTrue()
        ->and(Schema::hasTable('assessment_components'))->toBeTrue()
        ->and(Schema::hasTable('grade_bands'))->toBeTrue()
        ->and(Schema::hasTable('classification_bands'))->toBeTrue()
        ->and(Schema::hasTable('results'))->toBeTrue()
        ->and(Schema::hasTable('result_scores'))->toBeTrue()
        ->and(Schema::hasColumns('grading_schemes', [
            'name',
            'pass_mark',
            'version',
            'is_active',
            'active_flag',
            'repeat_policy',
            'resit_points_cap',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('results', [
            'student_id',
            'course_id',
            'semester_id',
            'grading_scheme_id',
            'scheme_version',
            'total',
            'grade',
            'points',
            'status',
            'entered_by',
            'approved_by',
            'published_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('result_scores', [
            'result_id',
            'component_id',
            'score',
        ]))->toBeTrue();
});
