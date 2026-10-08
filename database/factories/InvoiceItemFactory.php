<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FeeCategory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * description is a snapshot. A service copies it from the category.
     * The invoice total is not updated here.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'fee_category_id' => FeeCategory::factory(),
            'description' => 'Tuition',
            'amount_kobo' => 15_000_000,
            'sort' => 0,
        ];
    }
}
