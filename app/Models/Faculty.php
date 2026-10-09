<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FacultyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A faculty. Its code is unique across the institution.
 */
#[Fillable(['name', 'code'])]
class Faculty extends Model
{
    /** @use HasFactory<FacultyFactory> */
    use HasFactory;

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
}
