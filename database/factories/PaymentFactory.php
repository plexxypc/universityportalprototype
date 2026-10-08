<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
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
     * student_id is created separately from the invoice. A service later
     * requires them to be the same student.
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
            'student_id' => Student::factory(),
            'amount_kobo' => 500_000,
            'status' => PaymentStatus::Pending,
            'expires_at' => now()->addDay(),
            'last_checked_at' => null,
            'paid_at' => null,
        ];
    }
}
