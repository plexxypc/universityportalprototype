<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApplicantStatus;
use App\Enums\Gender;
use App\Enums\ImportSource;
use Database\Factories\ApplicantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An admissions applicant. This row is the intake snapshot, including names and contact fields.
 */
#[Fillable([
    'first_name',
    'last_name',
    'other_names',
    'email',
    'phone',
    'gender',
    'date_of_birth',
    'state_of_origin',
    'address',
    'programme_id',
    'level',
    'entry_session_id',
    'status',
    'source',
    'import_batch_id',
])]
class Applicant extends Model
{
    /** @use HasFactory<ApplicantFactory> */
    use HasFactory;

    /**
     * Cast gender, date of birth, status, and source.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'date_of_birth' => 'date',
            'status' => ApplicantStatus::class,
            'source' => ImportSource::class,
        ];
    }

    /**
     * Programme the applicant applied to.
     *
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * Entry session, when one was supplied.
     *
     * @return BelongsTo<AcademicSession, $this>
     */
    public function entrySession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'entry_session_id');
    }

    /**
     * Import batch that created this applicant, when it was imported.
     *
     * @return BelongsTo<ImportBatch, $this>
     */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    /**
     * Student created from this applicant, when converted.
     *
     * @return HasOne<Student, $this>
     */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }
}
