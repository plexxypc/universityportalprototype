<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentEventSource;
use App\Enums\PaymentGateway;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentEvent>
 */
class PaymentEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * event_key is the provider idempotency key. The row is insert-only.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => PaymentGateway::Demo,
            'event_key' => fake()->unique()->uuid(),
            'payload' => ['status' => 'pending'],
            'source' => PaymentEventSource::Callback,
            'payment_id' => Payment::factory(),
        ];
    }
}
