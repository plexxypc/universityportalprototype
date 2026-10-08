<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceAdjustmentType;
use Database\Factories\InvoiceAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A discount, waiver, or scholarship that reduces an invoice.
 *
 * amount_kobo is the reduction. reason and created_by are required.
 */
#[Fillable(['invoice_id', 'type', 'amount_kobo', 'reason', 'created_by'])]
class InvoiceAdjustment extends Model
{
    /** @use HasFactory<InvoiceAdjustmentFactory> */
    use HasFactory;

    /**
     * Cast the adjustment type.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InvoiceAdjustmentType::class,
        ];
    }

    /**
     * Invoice this reduction belongs to.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Account that recorded this reduction.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
