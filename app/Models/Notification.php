<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesVisibleTo;
use App\Models\Concerns\VisibleToUser;
use App\Support\Rbac\VisibilityKind;
use App\Support\Rbac\VisibilityProfile;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One in-app notification for one account.
 *
 * Rows live in notifications and use user_id. This is not Laravel's database
 * notification channel. read and unread exist so the Notifiable helpers filter
 * this table by read_at.
 */
#[Fillable([
    'user_id',
    'type',
    'data',
    'read_at',
])]
class Notification extends Model implements VisibleToUser
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    use ScopesVisibleTo;

    /**
     * Cast the payload and the read instant.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /**
     * Account this notification belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Limit the query to notifications that have been read.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRead(Builder $query): Builder
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Limit the query to notifications that have not been read.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Each account sees its own notifications. Super Admin sees every row.
     */
    protected static function visibilityProfile(): VisibilityProfile
    {
        return new VisibilityProfile(
            rows: [],
            kind: VisibilityKind::Notification,
        );
    }
}
