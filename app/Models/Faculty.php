<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesVisibleTo;
use App\Models\Concerns\VisibleToUser;
use App\Support\Rbac\VisibilityKind;
use App\Support\Rbac\VisibilityProfile;
use Database\Factories\FacultyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A faculty. Its code is unique across the institution.
 */
#[Fillable(['name', 'code'])]
class Faculty extends Model implements VisibleToUser
{
    /** @use HasFactory<FacultyFactory> */
    use HasFactory;

    use ScopesVisibleTo;

    /**
     * Departments in this faculty.
     *
     * @return HasMany<Department, $this>
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * Announcements aimed at this faculty.
     *
     * @return HasMany<Announcement, $this>
     */
    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    /**
     * Role assignments scoped to this faculty.
     *
     * @return HasMany<RoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * Faculties follow academic_structure.
     *
     * A lecturer sees only faculties that own an assigned course.
     */
    protected static function visibilityProfile(): VisibilityProfile
    {
        return new VisibilityProfile(
            rows: ['academic_structure'],
            kind: VisibilityKind::Faculty,
        );
    }
}
