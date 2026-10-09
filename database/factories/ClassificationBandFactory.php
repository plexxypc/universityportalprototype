<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ClassificationBand;
use App\Models\GradingScheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassificationBand>
 */
class ClassificationBandFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * CGPA bounds are fixed decimal strings. Overlap and gaps are
     * enforced in a service later.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scheme_id' => GradingScheme::factory(),
            'name' => 'First Class',
            'min_cgpa' => '4.50',
            'max_cgpa' => '5.00',
        ];
    }
}
