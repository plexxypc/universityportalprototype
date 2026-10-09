<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A student record.
 *
 * Name parts, gender, date of birth, state of origin, and address live here.
 * Email and phone live on the user. Faculty and department come from the programme.
 */
#[Fillable([
    'user_id',
    'applicant_id',
    'matric_no',
    'programme_id',
    'level',
    'entry_session_id',
    'status',
    'first_name',
    'last_name',
    'other_names',
    'gender',
    'date_of_birth',
    'state_of_origin',
    'address',
    'import_batch_id',
])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    /**
     * Cast gender, date of birth, and status.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'date_of_birth' => 'date',
            'status' => StudentStatus::class,
        ];
    }

    /**
     * Account for this student. Email and phone live on that account.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Applicant this student was converted from, when there was one.
     *
     * @return BelongsTo<Applicant, $this>
     */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    /**
     * Programme the student belongs to.
     *
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * Session in which the student entered.
     *
     * @return BelongsTo<AcademicSession, $this>
     */
    public function entrySession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'entry_session_id');
    }

    /**
     * Import batch that created this student, when it was imported.
     *
     * @return BelongsTo<ImportBatch, $this>
     */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    /**
     * Guardians for this student. Deleting the student deletes these rows.
     *
     * @return HasMany<Guardian, $this>
     */
    public function guardians(): HasMany
    {
        return $this->hasMany(Guardian::class);
    }

    /**
     * Course registrations for this student.
     *
     * @return HasMany<CourseRegistration, $this>
     */
    public function courseRegistrations(): HasMany
    {
        return $this->hasMany(CourseRegistration::class);
    }

    /**
     * Invoices for this student.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Payments made by this student.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Attendance marks for this student.
     *
     * @return HasMany<AttendanceRecord, $this>
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * Files owned by this student.
     *
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'owner');
    }
}
