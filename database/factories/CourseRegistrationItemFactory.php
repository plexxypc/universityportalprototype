<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course;
use App\Models\CourseRegistration;
use App\Models\CourseRegistrationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseRegistrationItem>
 */
class CourseRegistrationItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * credit_units is a snapshot. A service copies it from the course.
     * This factory stores 3, which matches the course factory default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_registration_id' => CourseRegistration::factory(),
            'course_id' => Course::factory(),
            'credit_units' => 3,
        ];
    }
}
