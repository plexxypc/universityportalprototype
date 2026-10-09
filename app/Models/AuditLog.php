<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change.
 *
 * Only the audit service inserts. Nothing updates or deletes. There is no
 * updated_at column. Database-level protection waits for Phase 19, after the
 * production host is chosen. Secrets in before and after are removed in a
 * service later.
 */
#[Fillable([
    'actor_id',
    'action',
    'entity',
    'entity_id',
    'before',
    'after',
    'ip',
])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Cast the stored snapshots.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    /**
     * Account that performed the action, when one is known.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
