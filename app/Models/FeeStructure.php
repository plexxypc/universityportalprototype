<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FeeStructureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The price of one fee category for a programme, level, and session.
 */
#[Fillable(['fee_category_id', 'programme_id', 'level', 'session_id', 'amount_kobo'])]
class FeeStructure extends Model
{
    /** @use HasFactory<FeeStructureFactory> */
    use HasFactory;

    /**
     * Fee category this price belongs to.
     *
     * @return BelongsTo<FeeCategory, $this>
     */
    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class);
    }

    /**
     * Programme this price applies to.
     *
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * Academic session this price applies to.
     *
     * @return BelongsTo<AcademicSession, $this>
     */
    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }
}
