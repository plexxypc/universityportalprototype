<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StaffStatus;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staff profile for one user. Faculty is the faculty of department_id, once departments exist.
 */
#[Fillable(['user_id', 'staff_no', 'title', 'department_id', 'status'])]
class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use HasFactory;

    /**
     * Cast the status string to the staff status enum.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StaffStatus::class,
        ];
    }

    /**
     * Account this staff profile belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Department this staff member belongs to, when one is set.
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
