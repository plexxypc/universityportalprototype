<?php

declare(strict_types=1);

use App\Enums\GradeRounding;
use App\Enums\RepeatPolicy;
use App\Services\GradingService;
use App\Support\Grading\DefaultScheme;

function grading(): GradingService
{
    return new GradingService;
}

it('keeps 69.50 in the B band and does not round it up to 70', function () {
    $grade = grading()->grade(6_950, DefaultScheme::bands(), DefaultScheme::passMarkHundredths());

    expect($grade['letter'])->toBe('B')
        ->and($grade['points_hundredths'])->toBe(400)
        ->and($grade['passed'])->toBeTrue();
});

it('places the default boundaries on the lower edge of each band', function () {
    $service = grading();
    $bands = DefaultScheme::bands();
    $pass = DefaultScheme::passMarkHundredths();

    expect($service->grade(0, $bands, $pass))->toMatchArray(['letter' => 'F', 'passed' => false])
        ->and($service->grade(3_999, $bands, $pass))->toMatchArray(['letter' => 'F', 'points_hundredths' => 0, 'passed' => false])
        ->and($service->grade(4_000, $bands, $pass))->toMatchArray(['letter' => 'E', 'points_hundredths' => 100, 'passed' => true])
        ->and($service->grade(4_499, $bands, $pass))->toMatchArray(['letter' => 'E', 'passed' => true])
        ->and($service->grade(4_500, $bands, $pass))->toMatchArray(['letter' => 'D', 'points_hundredths' => 200])
        ->and($service->grade(6_999, $bands, $pass))->toMatchArray(['letter' => 'B'])
        ->and($service->grade(7_000, $bands, $pass))->toMatchArray(['letter' => 'A', 'points_hundredths' => 500])
        ->and($service->grade(10_000, $bands, $pass))->toMatchArray(['letter' => 'A', 'passed' => true]);
});

it('adds continuous assessment and the exam before the lookup', function () {
    $total = grading()->total([3_000, 3_950]);
    $grade = grading()->grade($total, DefaultScheme::bands(), DefaultScheme::passMarkHundredths());

    expect($total)->toBe(6_950)
        ->and($grade['letter'])->toBe('B');
});

it('returns no average when the credit units are zero', function () {
    expect(grading()->average([]))->toBeNull()
        ->and(grading()->average([['points_hundredths' => 500, 'units' => 0]]))->toBeNull();
});

it('weights grade points by credit units', function () {
    $average = grading()->average([
        ['points_hundredths' => 500, 'units' => 3],
        ['points_hundredths' => 400, 'units' => 2],
    ]);

    expect($average)->toBe(460);
});

it('counts a failed course in the average', function () {
    $average = grading()->average([
        ['points_hundredths' => 0, 'units' => 3],
        ['points_hundredths' => 0, 'units' => 2],
    ]);

    expect($average)->toBe(0)
        ->and(grading()->classification($average, DefaultScheme::classifications()))->toBe('Fail / Probation review');
});

it('rounds a CGPA half up before classification', function () {
    $service = grading();
    $bands = DefaultScheme::classifications();
    $half_up = $service->average([
        ['points_hundredths' => 899, 'units' => 1],
        ['points_hundredths' => 0, 'units' => 1],
    ]);
    $below = $service->average([
        ['points_hundredths' => 449, 'units' => 4],
        ['points_hundredths' => 451, 'units' => 1],
    ]);

    expect($half_up)->toBe(450)
        ->and($service->classification($half_up, $bands))->toBe('First Class')
        ->and($below)->toBe(449)
        ->and($service->classification($below, $bands))->toBe('Second Class Upper');
});

it('uses the latest or the best attempt after the resit cap', function () {
    $service = grading();
    $sixty_two = $service->grade(6_200, DefaultScheme::bands(), DefaultScheme::passMarkHundredths())['points_hundredths'];
    $fifty_five = $service->grade(5_500, DefaultScheme::bands(), DefaultScheme::passMarkHundredths())['points_hundredths'];
    $eighty = $service->grade(8_000, DefaultScheme::bands(), DefaultScheme::passMarkHundredths())['points_hundredths'];

    $repeat = [
        ['points_hundredths' => $sixty_two, 'semester_order' => 1],
        ['points_hundredths' => $fifty_five, 'semester_order' => 2],
    ];

    expect($service->countedPoints($repeat, RepeatPolicy::Latest, null)['points_hundredths'])->toBe(300)
        ->and($service->countedPoints($repeat, RepeatPolicy::Best, null)['points_hundredths'])->toBe(400);

    $capped = [
        ['points_hundredths' => $fifty_five, 'semester_order' => 1],
        ['points_hundredths' => $eighty, 'semester_order' => 2],
    ];

    expect($service->countedPoints($capped, RepeatPolicy::Latest, 200)['points_hundredths'])->toBe(200)
        ->and($service->countedPoints($capped, RepeatPolicy::Best, 200)['points_hundredths'])->toBe(300);
});

it('breaks a best-attempt tie with the later semester', function () {
    $chosen = grading()->countedPoints([
        ['points_hundredths' => 400, 'semester_order' => 1],
        ['points_hundredths' => 400, 'semester_order' => 2],
    ], RepeatPolicy::Best, null);

    expect($chosen['semester_order'])->toBe(2)
        ->and($chosen['points_hundredths'])->toBe(400);
});

it('can round a total before lookup without changing the default callers', function () {
    $grade = grading()->grade(
        6_950,
        DefaultScheme::bands(),
        DefaultScheme::passMarkHundredths(),
        GradeRounding::HalfUp,
    );

    expect($grade['letter'])->toBe('A');
});
