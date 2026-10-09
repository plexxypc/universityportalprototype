<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesVisibleTo;
use App\Models\Concerns\VisibleToUser;
use App\Support\Rbac\VisibilityKind;
use App\Support\Rbac\VisibilityProfile;
use Database\Factories\ProgrammeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A programme. Its code is unique across the institution. degree is free text.
 */
#[Fillable(['department_id', 'name', 'code', 'degree', 'duration_years'])]
class Programme extends Model implements VisibleToUser
{
    /** @use HasFactory<ProgrammeFactory> */
    use HasFactory;

    use ScopesVisibleTo;

    /**
     * Department that offers this programme.
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Courses mapped into this programme.
     *
     * @return HasMany<ProgrammeCourse, $this>
     */
    public function programmeCourses(): HasMany
    {
        return $this->hasMany(ProgrammeCourse::class);
    }

    /**
     * Applicants to this programme.
     *
     * @return HasMany<Applicant, $this>
     */
    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }

    /**
     * Students enrolled on this programme.
     *
     * @return HasMany<Student, $this>
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /**
     * Fee prices for this programme.
     *
     * @return HasMany<FeeStructure, $this>
     */
    public function feeStructures(): HasMany
    {
        return $this->hasMany(FeeStructure::class);
    }

    /**
     * Announcements aimed at this programme.
     *
     * @return HasMany<Announcement, $this>
     */
    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    /**
     * Programmes follow academic_structure.
     *
     * A lecturer sees programmes whose department owns an assigned course.
     */
    protected static function visibilityProfile(): VisibilityProfile
    {
        return new VisibilityProfile(
            rows: ['academic_structure'],
            kind: VisibilityKind::Programme,
        );
    }
}
