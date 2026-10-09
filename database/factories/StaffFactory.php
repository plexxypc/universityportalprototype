<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StaffStatus;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'staff_no' => fake()->unique()->bothify('STF-####'),
            'title' => 'Dr',
            'department_id' => null,
            'status' => StaffStatus::Active,
        ];
    }

    /**
     * Mark the staff profile deactivated.
     */
    public function deactivated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => StaffStatus::Deactivated,
        ]);
    }
}
