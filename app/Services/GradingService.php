<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GradeRounding;
use App\Enums\RepeatPolicy;
use InvalidArgumentException;

/**
 * Turns hundredths into a total, a letter, grade points, an average, and a class.
 *
 * Every number is an integer. A course total is not rounded before the letter
 * lookup unless the caller passes a rounding mode. The CGPA division rounds
 * half up. Callers that omit the mode keep those two rules.
 */
class GradingService
{
    /**
     * Add component scores. Each value is already in hundredths.
     *
     * @param  list<int>  $score_hundredths
     */
    public function total(array $score_hundredths): int
    {
        $total = 0;

        foreach ($score_hundredths as $score) {
            $total += $score;
        }

        return $total;
    }

    /**
     * The letter for a total. The highest band whose minimum is at or below the total wins.
     *
     * @param  list<array{min_hundredths: int, max_hundredths: int, letter: string, points_hundredths: int, remark: string}>  $bands
     * @return array{letter: string, points_hundredths: int, remark: string, passed: bool}
     */
    public function grade(
        int $total_hundredths,
        array $bands,
        int $pass_mark_hundredths,
        GradeRounding $total_rounding = GradeRounding::None,
    ): array {
        $lookup_total = $this->roundHundredths($total_hundredths, $total_rounding);
        $chosen = null;

        foreach ($bands as $band) {
            if ($band['min_hundredths'] > $lookup_total) {
                continue;
            }

            if ($chosen === null || $band['min_hundredths'] > $chosen['min_hundredths']) {
                $chosen = $band;
            }
        }

        if ($chosen === null || $lookup_total > $chosen['max_hundredths']) {
            throw new InvalidArgumentException('No grade band covers that total.');
        }

        return [
            'letter' => $chosen['letter'],
            'points_hundredths' => $chosen['points_hundredths'],
            'remark' => $chosen['remark'],
            'passed' => $lookup_total >= $pass_mark_hundredths,
        ];
    }

    /**
     * GPA or CGPA in hundredths. Zero units returns null and does not divide.
     *
     * A failed course is included when the caller puts it in the list.
     *
     * @param  list<array{points_hundredths: int, units: int}>  $courses
     */
    public function average(
        array $courses,
        GradeRounding $division_rounding = GradeRounding::HalfUp,
    ): ?int {
        $units = 0;
        $weighted = 0;

        foreach ($courses as $course) {
            $units += $course['units'];
            $weighted += $course['points_hundredths'] * $course['units'];
        }

        if ($units === 0) {
            return null;
        }

        return $this->divide($weighted, $units, $division_rounding);
    }

    /**
     * The class whose minimum is the highest value at or below the rounded CGPA.
     *
     * @param  list<array{min_hundredths: int, max_hundredths: int, name: string}>  $bands
     */
    public function classification(?int $cgpa_hundredths, array $bands): ?string
    {
        if ($cgpa_hundredths === null) {
            return null;
        }

        $chosen = null;

        foreach ($bands as $band) {
            if ($band['min_hundredths'] > $cgpa_hundredths) {
                continue;
            }

            if ($chosen === null || $band['min_hundredths'] > $chosen['min_hundredths']) {
                $chosen = $band;
            }
        }

        if ($chosen === null || $cgpa_hundredths > $chosen['max_hundredths']) {
            throw new InvalidArgumentException('No classification band covers that CGPA.');
        }

        return $chosen['name'];
    }

    /**
     * Points that count after the resit cap, then Latest or Best.
     *
     * The cap applies to every attempt after the first. A Best tie uses the later semester.
     *
     * @param  list<array{points_hundredths: int, semester_order: int}>  $attempts
     * @return array{points_hundredths: int, semester_order: int}
     */
    public function countedPoints(array $attempts, RepeatPolicy $policy, ?int $resit_cap_hundredths): array
    {
        if ($attempts === []) {
            throw new InvalidArgumentException('A course needs at least one attempt.');
        }

        $adjusted = [];

        foreach ($attempts as $index => $attempt) {
            $points = $attempt['points_hundredths'];

            if ($index > 0 && $resit_cap_hundredths !== null && $points > $resit_cap_hundredths) {
                $points = $resit_cap_hundredths;
            }

            $adjusted[] = [
                'points' => $points,
                'order' => $attempt['semester_order'],
            ];
        }

        $chosen = $adjusted[0];

        foreach ($adjusted as $attempt) {
            if ($policy === RepeatPolicy::Latest) {
                if ($attempt['order'] >= $chosen['order']) {
                    $chosen = $attempt;
                }

                continue;
            }

            if ($attempt['points'] > $chosen['points'] || ($attempt['points'] === $chosen['points'] && $attempt['order'] > $chosen['order'])) {
                $chosen = $attempt;
            }
        }

        return [
            'points_hundredths' => $chosen['points'],
            'semester_order' => $chosen['order'],
        ];
    }

    /**
     * Round to the nearest whole mark (100 hundredths), or leave the value alone.
     */
    private function roundHundredths(int $hundredths, GradeRounding $rounding): int
    {
        if ($rounding === GradeRounding::None) {
            return $hundredths;
        }

        $whole = intdiv($hundredths, 100);
        $remainder = $hundredths % 100;

        if ($remainder >= 50) {
            $whole++;
        }

        return $whole * 100;
    }

    /**
     * Integer division. Half up when the remainder is at least half the divisor.
     */
    private function divide(int $numerator, int $denominator, GradeRounding $rounding): int
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Cannot divide by zero.');
        }

        $quotient = intdiv($numerator, $denominator);

        if ($rounding === GradeRounding::None) {
            return $quotient;
        }

        $remainder = $numerator % $denominator;

        if ($remainder * 2 >= $denominator) {
            $quotient++;
        }

        return $quotient;
    }
}
