<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AcademicStandingThresholdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Warning and probation CGPA for one grading-scheme version.
 *
 * No row means standing is off. A stored row has at least one of the two values.
 */
#[Fillable(['scheme_id', 'warning_cgpa', 'probation_cgpa'])]
class AcademicStandingThreshold extends Model
{
    /** @use HasFactory<AcademicStandingThresholdFactory> */
    use HasFactory;

    /**
     * Cast the thresholds as fixed decimal strings.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'warning_cgpa' => 'decimal:2',
            'probation_cgpa' => 'decimal:2',
        ];
    }

    /**
     * Scheme version these thresholds belong to.
     *
     * @return BelongsTo<GradingScheme, $this>
     */
    public function gradingScheme(): BelongsTo
    {
        return $this->belongsTo(GradingScheme::class, 'scheme_id');
    }
}
