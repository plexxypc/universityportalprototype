<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course;
use App\Models\ExamTimetable;
use App\Models\Semester;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamTimetable>
 */
class ExamTimetableFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * end_time is later than start_time on the same date. published_at stays
     * null until a service publishes the sitting.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'semester_id' => Semester::factory(),
            'exam_date' => fake()->date(),
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'venue' => 'Main Hall',
            'notes' => null,
            'published_at' => null,
        ];
    }
}
