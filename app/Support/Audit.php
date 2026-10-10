<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use App\Services\AuditService;
use Illuminate\Http\Request;

/**
 * The call other services use to write an audit row.
 *
 * This class does not insert and does not open a transaction. The ip is
 * REMOTE_ADDR when a request is passed. X-Forwarded-For is not read.
 */
final class Audit
{
    /**
     * Remember the only writer.
     */
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Record one row through AuditService.
     *
     * @param  array<int|string, mixed>|null  $before
     * @param  array<int|string, mixed>|null  $after
     */
    public function record(
        ?int $actor_id,
        string $action,
        string $entity,
        ?int $entity_id,
        ?array $before,
        ?array $after,
        ?string $ip,
    ): AuditLog {
        return $this->audit->record(
            $actor_id,
            $action,
            $entity,
            $entity_id,
            $before,
            $after,
            $ip,
        );
    }

    /**
     * Socket address from REMOTE_ADDR, or null when it is missing.
     */
    public function ipFromRequest(Request $request): ?string
    {
        $address = $request->server->get('REMOTE_ADDR');

        if (! is_string($address) || $address === '') {
            return null;
        }

        return $address;
    }
}
