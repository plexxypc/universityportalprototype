<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GradeBandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A letter, its points, and the score range that earns it.
 */
#[Fillable(['scheme_id', 'min_score', 'max_score', 'letter', 'points', 'remark'])]
class GradeBand extends Model
{
    /** @use HasFactory<GradeBandFactory> */
    use HasFactory;

    /**
     * Cast the score bounds and the grade points as fixed decimal strings.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'points' => 'decimal:2',
        ];
    }

    /**
     * Scheme version this band belongs to.
     *
     * @return BelongsTo<GradingScheme, $this>
     */
    public function gradingScheme(): BelongsTo
    {
        return $this->belongsTo(GradingScheme::class, 'scheme_id');
    }
}
