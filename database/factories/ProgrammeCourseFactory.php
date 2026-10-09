<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CourseType;
use App\Models\Course;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProgrammeCourse>
 */
class ProgrammeCourseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'programme_id' => Programme::factory(),
            'course_id' => Course::factory(),
            'level' => 100,
            'semester_no' => 1,
            'type' => CourseType::Core,
        ];
    }

    /**
     * Map the course as an elective.
     */
    public function elective(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CourseType::Elective,
        ]);
    }
}
