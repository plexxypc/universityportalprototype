<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Models\Concerns\ScopesVisibleTo;
use App\Models\Concerns\VisibleToUser;
use App\Support\Rbac\VisibilityKind;
use App\Support\Rbac\VisibilityProfile;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's mark for one class meeting.
 *
 * A second mark for the same student and meeting is rejected by the database.
 * Registration for the course is enforced in a service later.
 */
#[Fillable([
    'attendance_session_id',
    'student_id',
    'status',
])]
class AttendanceRecord extends Model implements VisibleToUser
{
    /** @use HasFactory<AttendanceRecordFactory> */
    use HasFactory;

    use ScopesVisibleTo;

    /**
     * Cast the mark.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
        ];
    }

    /**
     * Meeting this mark belongs to.
     *
     * @return BelongsTo<AttendanceSession, $this>
     */
    public function attendanceSession(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class);
    }

    /**
     * Student this mark belongs to.
     *
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Attendance follows the attendance cell.
     *
     * A lecturer sees marks for a meeting whose course and semester are assigned.
     */
    protected static function visibilityProfile(): VisibilityProfile
    {
        return new VisibilityProfile(
            rows: ['attendance'],
            kind: VisibilityKind::Attendance,
        );
    }
}
