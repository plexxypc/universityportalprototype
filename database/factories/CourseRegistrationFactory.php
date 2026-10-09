<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RegistrationStatus;
use App\Models\CourseRegistration;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
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

    /**
     * Mark the registration submitted.
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RegistrationStatus::Submitted,
            'submitted_at' => now(),
        ]);
    }

    /**
     * Mark the registration approved by a user.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RegistrationStatus::Approved,
            'submitted_at' => now(),
            'decided_at' => now(),
            'decided_by' => User::factory(),
        ]);
    }

    /**
     * Mark the registration rejected, with a reason.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RegistrationStatus::Rejected,
            'submitted_at' => now(),
            'decided_at' => now(),
            'decided_by' => User::factory(),
            'rejection_reason' => 'The unit total is outside the allowed range',
        ]);
    }
}
