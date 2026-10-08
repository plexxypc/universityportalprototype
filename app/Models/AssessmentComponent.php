<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AssessmentComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One named part of a scheme's total, such as continuous assessment or the exam.
 */
#[Fillable(['scheme_id', 'name', 'max_score', 'sort'])]
class AssessmentComponent extends Model
{
    /** @use HasFactory<AssessmentComponentFactory> */
    use HasFactory;

    /**
     * Cast the maximum score as a fixed decimal string.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_score' => 'decimal:2',
        ];
    }

    /**
     * Scheme version this component belongs to.
     *
     * @return BelongsTo<GradingScheme, $this>
     */
    public function gradingScheme(): BelongsTo
    {
        return $this->belongsTo(GradingScheme::class, 'scheme_id');
    }

    /**
     * Scores entered against this component.
     *
     * @return HasMany<ResultScore, $this>
     */
    public function resultScores(): HasMany
    {
        return $this->hasMany(ResultScore::class, 'component_id');
    }
}
