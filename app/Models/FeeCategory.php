<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FeeCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of fee, such as tuition or the ICT levy.
 */
#[Fillable(['name', 'code', 'description', 'is_active', 'sort'])]
class FeeCategory extends Model
{
    /** @use HasFactory<FeeCategoryFactory> */
    use HasFactory;

    /**
     * Cast the active flag.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Prices that use this category.
     *
     * @return HasMany<FeeStructure, $this>
     */
    public function feeStructures(): HasMany
    {
        return $this->hasMany(FeeStructure::class);
    }

    /**
     * Invoice lines that use this category.
     *
     * @return HasMany<InvoiceItem, $this>
     */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
