<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesVisibleTo;
use App\Models\Concerns\VisibleToUser;
use App\Support\Rbac\VisibilityKind;
use App\Support\Rbac\VisibilityProfile;
use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The receipt for one successful payment.
 *
 * The amount lives on the payment. A service creates this row once.
 */
#[Fillable(['payment_id', 'number'])]
class Receipt extends Model implements VisibleToUser
{
    /** @use HasFactory<ReceiptFactory> */
    use HasFactory;

    use ScopesVisibleTo;

    /**
     * Payment this receipt belongs to.
     *
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Receipts follow the fees cell through the payment's student.
     */
    protected static function visibilityProfile(): VisibilityProfile
    {
        return new VisibilityProfile(
            rows: ['fees', 'payments'],
            kind: VisibilityKind::Receipt,
        );
    }
}
