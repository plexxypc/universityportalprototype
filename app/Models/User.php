<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Support\Rbac\Permissions;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'phone', 'status', 'must_change_password', 'temp_password_expires_at', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Staff roles plus Student when a students row exists. Null until loaded.
     *
     * @var list<Role>|null
     */
    private ?array $resolvedRoles = null;

    /**
     * Faculty ids from Faculty Admin assignments. Loaded with the roles.
     *
     * @var list<int>
     */
    private array $facultyScopeIds = [];

    /**
     * Department ids from Department Officer assignments. Loaded with the roles.
     *
     * @var list<int>
     */
    private array $departmentScopeIds = [];

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
     * Staff roles from role assignments, plus Student when a students row exists.
     *
     * A Student value stored on role_assignments is ignored. The first call
     * loads the assignments and checks the students table. Later calls on
     * this same instance do not query again.
     *
     * @return list<Role>
     */
    public function roles(): array
    {
        $this->loadRoleContext();

        return $this->resolvedRoles ?? [];
    }

    /**
     * Whether this account has the given role.
     */
    public function hasRole(Role $role): bool
    {
        return in_array($role, $this->roles(), true);
    }

    /**
     * Whether the permission map grants this account a staff role.
     *
     * A students row does not count. A role that is not in the map is not
     * staff. The panel allows only an Active user with one of these roles,
     * in every environment.
     */
    public function hasStaffRole(): bool
    {
        foreach ($this->roles() as $role) {
            if (Permissions::isStaffRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this account may open the staff panel.
     *
     * Active and a staff role are both required. A student-only account
     * is refused. The check does not depend on the application environment.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'staff') {
            return false;
        }

        return $this->status === UserStatus::Active && $this->hasStaffRole();
    }

    /**
     * Faculty ids stored on this user's Faculty Admin assignments.
     *
     * @return list<int>
     */
    public function facultyIds(): array
    {
        $this->loadRoleContext();

        return $this->facultyScopeIds;
    }

    /**
     * Department ids stored on this user's Department Officer assignments.
     *
     * A Department Officer's faculty is not looked up here.
     *
     * @return list<int>
     */
    public function departmentIds(): array
    {
        $this->loadRoleContext();

        return $this->departmentScopeIds;
    }

    /**
     * Load staff roles, the derived student role, and the stored scope ids.
     */
    private function loadRoleContext(): void
    {
        if ($this->resolvedRoles !== null) {
            return;
        }

        if ($this->relationLoaded('roleAssignments')) {
            $assignments = $this->roleAssignments;
        } else {
            $assignments = $this->roleAssignments()->orderBy('id')->get();
            $this->setRelation('roleAssignments', $assignments);
        }

        $roles = [];
        $seen = [];
        $facultyIds = [];
        $departmentIds = [];

        foreach ($assignments as $assignment) {
            $role = $assignment->role;

            if ($role === Role::Student) {
                continue;
            }

            if (! isset($seen[$role->value])) {
                $seen[$role->value] = true;
                $roles[] = $role;
            }

            if ($role === Role::FacultyAdmin && $assignment->faculty_id !== null) {
                $facultyIds[] = (int) $assignment->faculty_id;
            }

            if ($role === Role::DepartmentOfficer && $assignment->department_id !== null) {
                $departmentIds[] = (int) $assignment->department_id;
            }
        }

        $isStudent = $this->relationLoaded('student')
            ? $this->getRelation('student') !== null
            : $this->student()->exists();

        if ($isStudent) {
            $roles[] = Role::Student;
        }

        $this->resolvedRoles = $roles;
        $this->facultyScopeIds = $facultyIds;
        $this->departmentScopeIds = $departmentIds;
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

    /**
     * Import batches this account ran.
     *
     * @return HasMany<ImportBatch, $this>
     */
    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    /**
     * Results this account entered.
     *
     * @return HasMany<Result, $this>
     */
    public function enteredResults(): HasMany
    {
        return $this->hasMany(Result::class, 'entered_by');
    }

    /**
     * Results this account approved.
     *
     * @return HasMany<Result, $this>
     */
    public function approvedResults(): HasMany
    {
        return $this->hasMany(Result::class, 'approved_by');
    }
}
