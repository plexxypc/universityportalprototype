<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guardian>
 */
class GuardianFactory extends Factory
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
            'name' => fake()->name(),
            'phone' => null,
            'email' => null,
            'relationship' => 'Parent',
        ];
    }
}
