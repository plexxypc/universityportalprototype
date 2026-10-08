<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * number is supplied here. The counters table generates it later.
     * Totals stay at zero so the default Unpaid row satisfies the checks.
     * A service keeps the totals equal to the lines and payments.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => fake()->unique()->bothify('INV-########'),
            'student_id' => Student::factory(),
            'session_id' => AcademicSession::factory(),
            'total_kobo' => 0,
            'adjustments_kobo' => 0,
            'paid_kobo' => 0,
            'status' => InvoiceStatus::Unpaid,
        ];
    }
}
