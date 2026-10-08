<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GradeBand;
use App\Models\GradingScheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradeBand>
 */
class GradeBandFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Bounds and points are fixed decimal strings. Covering 0 to 100
     * without gaps is enforced in a service later.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scheme_id' => GradingScheme::factory(),
            'min_score' => '70.00',
            'max_score' => '100.00',
            'letter' => 'A',
            'points' => '5.00',
            'remark' => 'Excellent',
        ];
    }
}
