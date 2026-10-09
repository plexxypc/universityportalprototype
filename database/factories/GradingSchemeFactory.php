<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RepeatPolicy;
use App\Models\GradingScheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradingScheme>
 */
class GradingSchemeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * active_flag is omitted on purpose. MySQL generates it from is_active.
     * is_active defaults to false so more than one scheme can be created.
     * version is unique because each edit is a new row.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('Scheme-###'),
            'pass_mark' => '40.00',
            'version' => fake()->unique()->numberBetween(1, 1_000_000),
            'is_active' => false,
            'repeat_policy' => RepeatPolicy::Latest,
            'resit_points_cap' => null,
        ];
    }

    /**
     * Mark this scheme as the single active scheme.
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
