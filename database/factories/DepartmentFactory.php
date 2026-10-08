<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Department;
use App\Models\Faculty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'faculty_id' => Faculty::factory(),
            'name' => fake()->unique()->company(),
            'code' => fake()->unique()->bothify('DP##'),
        ];
    }
}
