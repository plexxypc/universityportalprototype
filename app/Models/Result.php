<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ResultStatus;
use Database\Factories\ResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student's result for one course in one semester.
 *
 * total, grade, and points are all null or all set. A repeat in a later
 * semester is a separate row. The scheme row and the copied version stay
 * aligned in a service later.
 */
#[Fillable([
    'student_id',
    'course_id',
    'semester_id',
    'grading_scheme_id',
    'scheme_version',
    'total',
    'grade',
    'points',
    'status',
    'entered_by',
    'approved_by',
    'published_at',
])]
class Result extends Model
{
    /** @use HasFactory<ResultFactory> */
    use HasFactory;

    /**
     * Cast the stored score, the status, and the publication instant.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'points' => 'decimal:2',
            'status' => ResultStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * Student this result belongs to.
     *
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Course this result belongs to.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Semester this result belongs to.
     *
     * @return BelongsTo<Semester, $this>
     */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * Scheme version used to calculate this result.
     *
     * @return BelongsTo<GradingScheme, $this>
     */
    public function gradingScheme(): BelongsTo
    {
        return $this->belongsTo(GradingScheme::class);
    }

    /**
     * Account that entered this result.
     *
     * @return BelongsTo<User, $this>
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /**
     * Account that approved this result.
     *
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Component scores for this result.
     *
     * @return HasMany<ResultScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(ResultScore::class);
    }
}
