<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ResultStatus;
use App\Support\Results\ResultWriteGuard;
use Database\Factories\ResultScoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One component score on a result.
 *
 * The score cannot be negative. The component maximum is enforced in a service later.
 */
#[Fillable(['result_id', 'component_id', 'score'])]
class ResultScore extends Model
{
    /** @use HasFactory<ResultScoreFactory> */
    use HasFactory;

    /**
     * A score on a published result changes only during an amendment.
     */
    protected static function booted(): void
    {
        static::saving(function (ResultScore $score): void {
            self::guardPublished($score);
        });
        static::deleting(function (ResultScore $score): void {
            self::guardPublished($score);
        });
    }

    /**
     * Refuse a score write when the result is already Published.
     */
    private static function guardPublished(ResultScore $score): void
    {
        if (ResultWriteGuard::amending()) {
            return;
        }

        $result = Result::query()->find($score->result_id);

        if ($result instanceof Result && $result->status === ResultStatus::Published) {
            throw new RuntimeException(ResultWriteGuard::MESSAGE);
        }
    }

    /**
     * Cast the score as a fixed decimal string.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
        ];
    }

    /**
     * Result this score belongs to.
     *
     * @return BelongsTo<Result, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    /**
     * Component this score is for.
     *
     * @return BelongsTo<AssessmentComponent, $this>
     */
    public function assessmentComponent(): BelongsTo
    {
        return $this->belongsTo(AssessmentComponent::class, 'component_id');
    }
}
