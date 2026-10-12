<?php

declare(strict_types=1);

namespace App\Support\Grading;

/**
 * The Nigerian 5-point numbers, stored as integer hundredths.
 *
 * The calculator does not read this class. A button loads it when no scheme
 * exists. 60.00–69.99 is 6000–6999. 70.00–100.00 is 7000–10000. Grade points
 * use the same hundredths, so 5.00 is 500.
 */
final class DefaultScheme
{
    public const string NAME = 'Nigerian 5-point';

    /**
     * 40.00. The fail band ends one hundredth below this.
     */
    public static function passMarkHundredths(): int
    {
        return 4_000;
    }

    /**
     * @return list<array{name: string, max_hundredths: int, sort: int}>
     */
    public static function components(): array
    {
        return [
            ['name' => 'Continuous Assessment', 'max_hundredths' => 3_000, 'sort' => 1],
            ['name' => 'Exam', 'max_hundredths' => 7_000, 'sort' => 2],
        ];
    }

    /**
     * @return list<array{min_hundredths: int, max_hundredths: int, letter: string, points_hundredths: int, remark: string}>
     */
    public static function bands(): array
    {
        return [
            ['min_hundredths' => 7_000, 'max_hundredths' => 10_000, 'letter' => 'A', 'points_hundredths' => 500, 'remark' => 'Excellent'],
            ['min_hundredths' => 6_000, 'max_hundredths' => 6_999, 'letter' => 'B', 'points_hundredths' => 400, 'remark' => 'Very good'],
            ['min_hundredths' => 5_000, 'max_hundredths' => 5_999, 'letter' => 'C', 'points_hundredths' => 300, 'remark' => 'Good'],
            ['min_hundredths' => 4_500, 'max_hundredths' => 4_999, 'letter' => 'D', 'points_hundredths' => 200, 'remark' => 'Fair'],
            ['min_hundredths' => 4_000, 'max_hundredths' => 4_499, 'letter' => 'E', 'points_hundredths' => 100, 'remark' => 'Pass'],
            ['min_hundredths' => 0, 'max_hundredths' => 3_999, 'letter' => 'F', 'points_hundredths' => 0, 'remark' => 'Fail'],
        ];
    }

    /**
     * @return list<array{min_hundredths: int, max_hundredths: int, name: string}>
     */
    public static function classifications(): array
    {
        return [
            ['min_hundredths' => 450, 'max_hundredths' => 500, 'name' => 'First Class'],
            ['min_hundredths' => 350, 'max_hundredths' => 449, 'name' => 'Second Class Upper'],
            ['min_hundredths' => 240, 'max_hundredths' => 349, 'name' => 'Second Class Lower'],
            ['min_hundredths' => 150, 'max_hundredths' => 239, 'name' => 'Third Class'],
            ['min_hundredths' => 100, 'max_hundredths' => 149, 'name' => 'Pass'],
            ['min_hundredths' => 0, 'max_hundredths' => 99, 'name' => 'Fail / Probation review'],
        ];
    }
}
