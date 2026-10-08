<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AcademicSession;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\Programme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeStructure>
 */
class FeeStructureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * amount_kobo is 15,000,000 kobo (150,000 naira).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fee_category_id' => FeeCategory::factory(),
            'programme_id' => Programme::factory(),
            'level' => 100,
            'session_id' => AcademicSession::factory(),
            'amount_kobo' => 15_000_000,
        ];
    }
}
