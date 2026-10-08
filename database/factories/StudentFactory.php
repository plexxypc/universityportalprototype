<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * matric_no is supplied here. The counters table generates it later.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'applicant_id' => null,
            'matric_no' => fake()->unique()->bothify('CSC/####/####'),
            'programme_id' => Programme::factory(),
            'level' => 100,
            'entry_session_id' => AcademicSession::factory(),
            'status' => StudentStatus::Active,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'other_names' => null,
            'gender' => Gender::Male,
            'date_of_birth' => '2004-05-01',
            'state_of_origin' => 'Lagos',
            'address' => null,
            'import_batch_id' => null,
        ];
    }
}
