<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AcademicSession;
use App\Models\Semester;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Semester>
 */
class SemesterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * active_flag is omitted on purpose. MySQL generates it from is_active.
     * is_active defaults to false so more than one semester can be created.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_id' => AcademicSession::factory(),
            'name' => fake()->unique()->bothify('Semester-###'),
            'is_active' => false,
            'registration_deadline' => null,
            'add_drop_deadline' => null,
        ];
    }

    /**
     * Mark this semester as the single active semester.
     *
     * active_flag is generated. Only one row in the table may use this state.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => true,
        ]);
    }
}
