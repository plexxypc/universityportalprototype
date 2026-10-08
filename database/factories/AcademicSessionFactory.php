<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AcademicSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicSession>
 */
class AcademicSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * current_flag is omitted on purpose. MySQL generates it from is_current.
     * is_current defaults to false so more than one session can be created.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->numerify('20##/20##-###'),
            'is_current' => false,
        ];
    }
}
