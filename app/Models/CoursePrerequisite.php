<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CoursePrerequisiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A required course for another course. A course cannot require itself.
 */
#[Fillable(['course_id', 'prerequisite_course_id'])]
class CoursePrerequisite extends Model
{
    /** @use HasFactory<CoursePrerequisiteFactory> */
    use HasFactory;

    /**
     * Course that requires the prerequisite.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Course that must be passed first.
     *
     * @return BelongsTo<Course, $this>
     */
    public function prerequisite(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'prerequisite_course_id');
    }
}
