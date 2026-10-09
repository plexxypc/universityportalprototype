<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AttendanceSession;
use App\Models\Course;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceSession>
 */
class AttendanceSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'semester_id' => Semester::factory(),
            'session_date' => fake()->date(),
            'topic' => fake()->sentence(3),
            'created_by' => User::factory(),
        ];
    }
}
