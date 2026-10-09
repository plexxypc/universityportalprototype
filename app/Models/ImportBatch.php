<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportSource;
use App\Enums\ImportTarget;
use Database\Factories\ImportBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One import run. The failure report path is on the local demo disk.
 */
#[Fillable([
    'user_id',
    'source',
    'target',
    'status',
    'total_rows',
    'processed_rows',
    'created_count',
    'updated_count',
    'skipped_count',
    'failed_count',
    'original_name',
    'failure_report_path',
    'completed_at',
])]
class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory;

    /**
     * Cast the source, target, status, and completion instant.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ImportSource::class,
            'target' => ImportTarget::class,
            'status' => ImportBatchStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Account that ran this import.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Applicants created from this batch.
     *
     * @return HasMany<Applicant, $this>
     */
    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }

    /**
     * Students created from this batch.
     *
     * @return HasMany<Student, $this>
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }
}
