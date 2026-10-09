<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmailStatus;
use App\Models\EmailOutbox;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailOutbox>
 */
class EmailOutboxFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default body is a placeholder. It does not contain a password.
     * Redaction of a credentials email is enforced in a service later.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'recipient_email' => fake()->safeEmail(),
            'template' => 'announcement',
            'subject' => 'A notice from the portal',
            'body_html' => '<p>Notice</p>',
            'body_text' => 'Notice',
            'status' => EmailStatus::Queued,
            'attempts' => 0,
            'last_error' => null,
            'sent_at' => null,
            'redacted_at' => null,
        ];
    }
}
