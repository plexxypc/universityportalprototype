<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ExamTimetableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One exam sitting for a course in a semester.
 *
 * Informational only. There is no CBT link (ADR-004). start_time and end_time
 * stay strings because they are clock times on exam_date, not instants.
 * Clash detection is enforced in a service later.
 */
#[Fillable([
    'course_id',
    'semester_id',
    'exam_date',
    'start_time',
    'end_time',
    'venue',
    'notes',
    'published_at',
])]
class ExamTimetable extends Model
{
    /** @use HasFactory<ExamTimetableFactory> */
    use HasFactory;

    protected $table = 'exam_timetable';

    /**
     * Cast the exam date and the publication instant.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exam_date' => 'date',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Course this sitting belongs to.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Semester this sitting belongs to.
     *
     * @return BelongsTo<Semester, $this>
     */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }
}
