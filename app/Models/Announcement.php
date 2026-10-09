<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnnouncementAudience;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message published to one audience.
 *
 * The audience columns must match the audience. Resolving that audience to
 * accounts, and sending the optional email, are enforced in a service later.
 */
#[Fillable([
    'author_id',
    'title',
    'body',
    'audience',
    'faculty_id',
    'department_id',
    'programme_id',
    'level',
    'send_email',
    'published_at',
])]
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    /**
     * Cast the audience, the optional email flag, and the publication instant.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => AnnouncementAudience::class,
            'send_email' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Account that wrote this announcement.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Faculty this announcement targets, when the audience is a faculty.
     *
     * @return BelongsTo<Faculty, $this>
     */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    /**
     * Department this announcement targets, when the audience is a department.
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Programme this announcement targets, when the audience is a programme.
     *
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }
}
