<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AcademicStandingThreshold;
use App\Models\GradingScheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicStandingThreshold>
 */
class AcademicStandingThresholdFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * One value is set so the row is not both null. The service refuses a row
     * that sets neither threshold.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scheme_id' => GradingScheme::factory(),
            'warning_cgpa' => '1.50',
            'probation_cgpa' => '1.00',
        ];
    }
}
