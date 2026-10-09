<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Counter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Counter>
 */
class CounterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The key is unique. Matric generation is enforced in a service later.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->bothify('sequence-????'),
            'value' => 0,
        ];
    }
}
