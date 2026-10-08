<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RegistrationStatus;
use App\Models\CourseRegistration;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseRegistration>
 */
class CourseRegistrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'semester_id' => Semester::factory(),
            'status' => RegistrationStatus::Draft,
            'total_units' => 0,
            'submitted_at' => null,
            'decided_at' => null,
            'decided_by' => null,
            'rejection_reason' => null,
        ];
    }
}
