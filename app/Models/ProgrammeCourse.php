<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CourseType;
use Database\Factories\ProgrammeCourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One course mapped to one programme, with level, semester number and type.
 */
#[Fillable(['programme_id', 'course_id', 'level', 'semester_no', 'type'])]
class ProgrammeCourse extends Model
{
    /** @use HasFactory<ProgrammeCourseFactory> */
    use HasFactory;

    /**
     * Cast the core/elective type.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CourseType::class,
        ];
    }

    /**
     * Programme this mapping belongs to.
     *
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * Course this mapping belongs to.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
