<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ClassificationBandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A class of degree and the CGPA range that earns it.
 */
#[Fillable(['scheme_id', 'name', 'min_cgpa', 'max_cgpa'])]
class ClassificationBand extends Model
{
    /** @use HasFactory<ClassificationBandFactory> */
    use HasFactory;

    /**
     * Cast the CGPA bounds as fixed decimal strings.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_cgpa' => 'decimal:2',
            'max_cgpa' => 'decimal:2',
        ];
    }

    /**
     * Scheme version this class belongs to.
     *
     * @return BelongsTo<GradingScheme, $this>
     */
    public function gradingScheme(): BelongsTo
    {
        return $this->belongsTo(GradingScheme::class, 'scheme_id');
    }
}
