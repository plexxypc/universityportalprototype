<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesVisibleTo;
use App\Models\Concerns\VisibleToUser;
use App\Support\Rbac\VisibilityKind;
use App\Support\Rbac\VisibilityProfile;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A catalogue course. Code is unique across the institution.
 */
#[Fillable(['department_id', 'code', 'title', 'credit_units'])]
class Course extends Model implements VisibleToUser
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    use ScopesVisibleTo;

    /**
     * Department that owns this course.
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Programme mappings for this course.
     *
     * @return HasMany<ProgrammeCourse, $this>
     */
    public function programmeCourses(): HasMany
    {
        return $this->hasMany(ProgrammeCourse::class);
    }

    /**
     * Prerequisite rows where this course is the course that requires another.
     *
     * @return HasMany<CoursePrerequisite, $this>
     */
    public function prerequisites(): HasMany
    {
        return $this->hasMany(CoursePrerequisite::class);
    }

    /**
     * Prerequisite rows where this course is the course that must be passed first.
     *
     * @return HasMany<CoursePrerequisite, $this>
     */
    public function dependents(): HasMany
    {
        return $this->hasMany(CoursePrerequisite::class, 'prerequisite_course_id');
    }

    /**
     * Staff assignments for this course.
     *
     * @return HasMany<CourseAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(CourseAssignment::class);
    }

    /**
     * Registration items that snapshot this course.
     *
     * @return HasMany<CourseRegistrationItem, $this>
     */
    public function registrationItems(): HasMany
    {
        return $this->hasMany(CourseRegistrationItem::class);
    }

    /**
     * Class meetings for this course.
     *
     * @return HasMany<AttendanceSession, $this>
     */
    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class);
    }

    /**
     * Exam sittings for this course.
     *
     * @return HasMany<ExamTimetable, $this>
     */
    public function examTimetable(): HasMany
    {
        return $this->hasMany(ExamTimetable::class);
    }

    /**
     * Results recorded for this course.
     *
     * @return HasMany<Result, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    /**
     * Courses follow academic_structure.
     *
     * A lecturer sees only courses on course_assignments, which is narrower
     * than the unscoped view cell.
     */
    protected static function visibilityProfile(): VisibilityProfile
    {
        return new VisibilityProfile(
            rows: ['academic_structure'],
            kind: VisibilityKind::Course,
        );
    }
}
