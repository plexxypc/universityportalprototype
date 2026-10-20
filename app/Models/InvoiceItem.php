<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesVisibleTo;
use App\Models\Concerns\VisibleToUser;
use App\Support\Rbac\VisibilityKind;
use App\Support\Rbac\VisibilityProfile;
use Database\Factories\InvoiceItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One fee line on an invoice.
 *
 * description is the category label at the time the line was created.
 */
#[Fillable(['invoice_id', 'fee_category_id', 'description', 'amount_kobo', 'sort'])]
class InvoiceItem extends Model implements VisibleToUser
{
    /** @use HasFactory<InvoiceItemFactory> */
    use HasFactory;

    use ScopesVisibleTo;

    /**
     * Invoice this line belongs to.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Fee category this line records.
     *
     * @return BelongsTo<FeeCategory, $this>
     */
    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class);
    }

    /**
     * Lines follow the invoice, which follows the fees cell.
     */
    protected static function visibilityProfile(): VisibilityProfile
    {
        return new VisibilityProfile(
            rows: ['fees', 'payments'],
            kind: VisibilityKind::InvoiceLine,
        );
    }
}
