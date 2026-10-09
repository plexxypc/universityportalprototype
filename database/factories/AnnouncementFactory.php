<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AnnouncementAudience;
use App\Models\Announcement;
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
}
