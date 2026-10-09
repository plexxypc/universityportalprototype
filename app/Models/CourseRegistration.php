<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\Concerns\ScopesVisibleTo;
use App\Models\Concerns\VisibleToUser;
use App\Support\Rbac\VisibilityKind;
use App\Support\Rbac\VisibilityProfile;
use Database\Factories\CourseRegistrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One course registration for a student in a semester.
 */
#[Fillable([
    'student_id',
    'semester_id',
    'status',
    'total_units',
    'submitted_at',
    'decided_at',
    'decided_by',
    'rejection_reason',
])]
class CourseRegistration extends Model implements VisibleToUser
{
    /** @use HasFactory<CourseRegistrationFactory> */
    use HasFactory;

    use ScopesVisibleTo;

    /**
     * Cast the status and the decision instants.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RegistrationStatus::class,
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * Student who owns this registration.
     *
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Semester this registration belongs to.
     *
     * @return BelongsTo<Semester, $this>
     */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * Account that approved or rejected this registration.
     *
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Courses on this registration. Each item keeps its own unit snapshot.
     *
     * @return HasMany<CourseRegistrationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CourseRegistrationItem::class);
    }

    /**
     * Registrations follow student records, submit, and approve.
     *
     * Lecturers are skipped. The student_records cell would include their
     * courses, and a registration is not one of those rows.
     */
    protected static function visibilityProfile(): VisibilityProfile
    {
        return new VisibilityProfile(
            rows: ['student_records', 'course_registration_submit', 'course_registration_approve'],
            kind: VisibilityKind::StudentId,
            skipRoles: [Role::Lecturer],
        );
    }
}
