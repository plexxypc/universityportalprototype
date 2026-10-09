<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ResultStatus;
use App\Models\Course;
use App\Models\GradingScheme;
use App\Models\Result;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Result>
 */
class ResultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * total, grade, and points stay null together so the row is a draft.
     * scheme_version is a copy of the scheme row. This default is 1 and is
     * not read back from the linked row. A service later keeps them equal.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'course_id' => Course::factory(),
            'semester_id' => Semester::factory(),
            'grading_scheme_id' => GradingScheme::factory(),
            'scheme_version' => 1,
            'total' => null,
            'grade' => null,
            'points' => null,
            'status' => ResultStatus::Draft,
            'entered_by' => User::factory(),
            'approved_by' => null,
            'published_at' => null,
        ];
    }

    /**
     * Mark the result submitted, with the score fields still empty.
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ResultStatus::Submitted,
            'total' => null,
            'grade' => null,
            'points' => null,
            'approved_by' => null,
            'published_at' => null,
        ]);
    }

    /**
     * Mark the result approved, with the score fields set together.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'total' => '70.00',
            'grade' => 'A',
            'points' => '5.00',
            'status' => ResultStatus::Approved,
            'approved_by' => User::factory(),
            'published_at' => null,
        ]);
    }

    /**
     * Mark the result published. Published requires published_at.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'total' => '70.00',
            'grade' => 'A',
            'points' => '5.00',
            'status' => ResultStatus::Published,
            'approved_by' => User::factory(),
            'published_at' => now(),
        ]);
    }
}
