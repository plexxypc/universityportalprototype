<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ApplicantStatus;
use App\Enums\Gender;
use App\Enums\ImportSource;
use App\Models\Applicant;
use App\Models\Programme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Applicant>
 */
class ApplicantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'other_names' => null,
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'gender' => Gender::Female,
            'date_of_birth' => '2004-05-01',
            'state_of_origin' => 'Lagos',
            'address' => null,
            'programme_id' => Programme::factory(),
            'level' => 100,
            'entry_session_id' => null,
            'status' => ApplicantStatus::Applied,
            'source' => ImportSource::Manual,
            'import_batch_id' => null,
        ];
    }

    /**
     * Mark the applicant admitted.
     */
    public function admitted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ApplicantStatus::Admitted,
        ]);
    }

    /**
     * Mark the applicant rejected.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ApplicantStatus::Rejected,
        ]);
    }
}
