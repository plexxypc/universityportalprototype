<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Department;
use App\Models\Programme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Programme>
 */
class ProgrammeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'name' => fake()->unique()->words(3, true),
            'code' => fake()->unique()->bothify('PG####'),
            'degree' => 'BSc',
            'duration_years' => 4,
        ];
    }
}
