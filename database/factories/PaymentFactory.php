<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * reference is supplied here. The counters table generates it later.
     * provider_reference stays null until a gateway returns one.
     * student_id is the invoice's student.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => fake()->unique()->bothify('PAY-################'),
            'gateway' => PaymentGateway::Demo,
            'provider_reference' => null,
            'invoice_id' => Invoice::factory(),
            'student_id' => fn (array $attributes): int => (int) Invoice::query()
                ->findOrFail($attributes['invoice_id'])
                ->student_id,
            'amount_kobo' => 500_000,
            'status' => PaymentStatus::Pending,
            'expires_at' => now()->addDay(),
            'last_checked_at' => null,
            'paid_at' => null,
        ];
    }

    /**
     * Mark the payment successful. Successful requires paid_at.
     */
    public function successful(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Successful,
            'paid_at' => now(),
        ]);
    }

    /**
     * Mark the payment failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Failed,
            'paid_at' => null,
        ]);
    }
}
