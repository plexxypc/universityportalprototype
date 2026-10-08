<?php

declare(strict_types=1);

namespace App\Models;

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
class Programme extends Model
{
    /** @use HasFactory<ProgrammeFactory> */
    use HasFactory;

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
}
