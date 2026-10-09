<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'phone', 'status', 'must_change_password', 'temp_password_expires_at', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'must_change_password' => 'boolean',
            'temp_password_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Roles granted to this account.
     *
     * @return HasMany<RoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * Staff profile for this account, when the user is staff.
     *
     * @return HasOne<Staff, $this>
     */
    public function staff(): HasOne
    {
        return $this->hasOne(Staff::class);
    }

    /**
     * Student profile for this account, when the user is a student.
     *
     * @return HasOne<Student, $this>
     */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    /**
     * Registrations this account approved or rejected.
     *
     * @return HasMany<CourseRegistration, $this>
     */
    public function decidedRegistrations(): HasMany
    {
        return $this->hasMany(CourseRegistration::class, 'decided_by');
    }

    /**
     * Invoice reductions this account recorded.
     *
     * @return HasMany<InvoiceAdjustment, $this>
     */
    public function invoiceAdjustments(): HasMany
    {
        return $this->hasMany(InvoiceAdjustment::class, 'created_by');
    }

    /**
     * Class meetings this account created.
     *
     * @return HasMany<AttendanceSession, $this>
     */
    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class, 'created_by');
    }

    /**
     * Announcements this account wrote.
     *
     * @return HasMany<Announcement, $this>
     */
    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class, 'author_id');
    }

    /**
     * In-app notifications for this account.
     *
     * The Notifiable trait would point this at Laravel's database-notification
     * table. This portal never uses that channel. NotificationService writes
     * these rows, and the relation uses notifications.user_id.
     *
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Emails recorded for this account.
     *
     * @return HasMany<EmailOutbox, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(EmailOutbox::class);
    }

    /**
     * Audit rows that name this account as the actor.
     *
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }
}
