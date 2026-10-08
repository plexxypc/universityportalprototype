<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InvoiceAdjustmentType;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceAdjustment>
 */
class InvoiceAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * amount_kobo is a reduction. The invoice adjustments total is not updated here.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'type' => InvoiceAdjustmentType::Scholarship,
            'amount_kobo' => 1_000_000,
            'reason' => 'Merit scholarship for the session',
            'created_by' => User::factory(),
        ];
    }
}
