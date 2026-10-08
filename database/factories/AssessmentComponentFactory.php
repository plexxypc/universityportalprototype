<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AssessmentComponent;
use App\Models\GradingScheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssessmentComponent>
 */
class AssessmentComponentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * max_score is a fixed decimal string. A service later makes the
     * components of one scheme total 100.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scheme_id' => GradingScheme::factory(),
            'name' => fake()->unique()->bothify('Component-###'),
            'max_score' => '30.00',
            'sort' => 1,
        ];
    }
}
