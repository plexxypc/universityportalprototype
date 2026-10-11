<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\AuditLogImmutableException;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change.
 *
 * Only the audit service inserts. save() on an existing row and delete()
 * throw. A query-builder update still bypasses this model. Database-level
 * protection waits for Phase 19, after the production host is chosen.
 * AuditService removes secrets from before and after.
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
     * Reject a second write. A new row may still be inserted.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = [])
    {
        if ($this->exists) {
            throw AuditLogImmutableException::forUpdate();
        }

        return parent::save($options);
    }

    /**
     * Reject a delete.
     */
    public function delete()
    {
        throw AuditLogImmutableException::forDelete();
    }

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
