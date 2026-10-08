<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InstitutionSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstitutionSetting>
 */
class InstitutionSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * singleton_key is omitted on purpose. MySQL defaults it to 1.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'University Portal',
            'code' => 'UNI',
            'logo_path' => null,
            'address' => null,
            'phone' => null,
            'email' => null,
            'motto' => null,
            'matric_pattern' => '{DEPT}/{YEAR}/{SEQ4}',
            'min_units' => 15,
            'max_units' => 24,
            'approval_required' => true,
            'withhold_results_for_debt' => false,
            'attendance_threshold' => 75,
            'require_minimum_payment' => false,
        ];
    }
}
