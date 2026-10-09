<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course;
use App\Models\CoursePrerequisite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoursePrerequisite>
 */
class CoursePrerequisiteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The two courses are different rows, so the self-prerequisite check passes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'prerequisite_course_id' => Course::factory(),
        ];
    }
}
