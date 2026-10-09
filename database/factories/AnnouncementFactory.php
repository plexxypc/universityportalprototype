<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AnnouncementAudience;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default audience is everyone, so the scope columns stay null.
     * published_at stays null until a service publishes the announcement.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'author_id' => User::factory(),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'audience' => AnnouncementAudience::All,
            'faculty_id' => null,
            'department_id' => null,
            'programme_id' => null,
            'level' => null,
            'send_email' => false,
            'published_at' => null,
        ];
    }

    /**
     * Address one faculty, and no other scope.
     */
    public function forFaculty(): static
    {
        return $this->state(fn (array $attributes): array => [
            'audience' => AnnouncementAudience::Faculty,
            'faculty_id' => Faculty::factory(),
            'department_id' => null,
            'programme_id' => null,
            'level' => null,
        ]);
    }

    /**
     * Address one department, and no other scope.
     */
    public function forDepartment(): static
    {
        return $this->state(fn (array $attributes): array => [
            'audience' => AnnouncementAudience::Department,
            'faculty_id' => null,
            'department_id' => Department::factory(),
            'programme_id' => null,
            'level' => null,
        ]);
    }

    /**
     * Address one programme, and no other scope.
     */
    public function forProgramme(): static
    {
        return $this->state(fn (array $attributes): array => [
            'audience' => AnnouncementAudience::Programme,
            'faculty_id' => null,
            'department_id' => null,
            'programme_id' => Programme::factory(),
            'level' => null,
        ]);
    }

    /**
     * Address one level, and no other scope.
     */
    public function forLevel(): static
    {
        return $this->state(fn (array $attributes): array => [
            'audience' => AnnouncementAudience::Level,
            'faculty_id' => null,
            'department_id' => null,
            'programme_id' => null,
            'level' => 200,
        ]);
    }

    /**
     * Mark the announcement published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'published_at' => now(),
        ]);
    }
}
