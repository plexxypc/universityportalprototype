<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One payment attempt against an invoice.
 *
 * reference is the internal id. provider_reference is the RRR or transaction
 * reference, and it stays empty until the gateway returns it. A service later
 * requires student_id to be the invoice student.
 */
#[Fillable([
    'reference',
    'gateway',
    'provider_reference',
    'invoice_id',
    'student_id',
    'amount_kobo',
    'status',
    'expires_at',
    'last_checked_at',
    'paid_at',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * Cast the gateway, status, and payment instants.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gateway' => PaymentGateway::class,
            'status' => PaymentStatus::class,
            'expires_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Invoice this payment is for.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Student who made this payment.
     *
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Triggers recorded for this payment.
     *
     * @return HasMany<PaymentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    /**
     * Receipt for this payment, once it has succeeded.
     *
     * @return HasOne<Receipt, $this>
     */
    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }
}
