<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentEventSource;
use App\Enums\PaymentGateway;
use Database\Factories\PaymentEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One trigger for a payment: a callback, notification, poll, or manual check.
 *
 * Rows are inserted and not updated. updated_at stays because every table has it.
 */
#[Fillable(['provider', 'event_key', 'payload', 'source', 'payment_id'])]
class PaymentEvent extends Model
{
    /** @use HasFactory<PaymentEventFactory> */
    use HasFactory;

    /**
     * Cast the provider, the stored payload, and the source.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentGateway::class,
            'payload' => 'array',
            'source' => PaymentEventSource::class,
        ];
    }

    /**
     * Payment this event was matched to, when it was matched.
     *
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
