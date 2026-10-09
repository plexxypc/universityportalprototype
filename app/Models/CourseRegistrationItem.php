<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CourseRegistrationItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One course on a registration.
 *
 * credit_units is the catalogue value at registration time. If the course
 * later changes, this snapshot and the registration total stay as they were.
 */
#[Fillable(['course_registration_id', 'course_id', 'credit_units'])]
class CourseRegistrationItem extends Model
{
    /** @use HasFactory<CourseRegistrationItemFactory> */
    use HasFactory;

    /**
     * Registration this item belongs to.
     *
     * @return BelongsTo<CourseRegistration, $this>
     */
    public function courseRegistration(): BelongsTo
    {
        return $this->belongsTo(CourseRegistration::class);
    }

    /**
     * Catalogue course this item records.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
