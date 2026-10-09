<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * One bcrypt hash for every user this process creates.
     *
     * The plaintext is a random 32-character string and is not kept.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(Str::password(32)),
            'remember_token' => Str::random(10),
            'phone' => null,
            'status' => UserStatus::Active,
            'must_change_password' => false,
            'temp_password_expires_at' => null,
            'last_login_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Mark the account suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UserStatus::Suspended,
        ]);
    }

    /**
     * Mark the account deactivated.
     */
    public function deactivated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UserStatus::Deactivated,
        ]);
    }

    /**
     * Require a password change and set a future temporary-password expiry.
     */
    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes): array => [
            'must_change_password' => true,
            'temp_password_expires_at' => now()->addDays(7),
        ]);
    }
}
