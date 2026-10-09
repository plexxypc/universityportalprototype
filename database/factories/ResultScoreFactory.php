<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AssessmentComponent;
use App\Models\Result;
use App\Models\ResultScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResultScore>
 */
class ResultScoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * score is a fixed decimal string and is not negative.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'result_id' => Result::factory(),
            'component_id' => AssessmentComponent::factory(),
            'score' => '20.00',
        ];
    }
}
