<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuditTransactionException;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Inserts one audit row inside the caller's transaction.
 *
 * There is no update and no delete. The caller opens the transaction.
 * Secrets are removed from before and after by key name, down to depth 8.
 */
final class AuditService
{
    public const string ACTION_LOGIN = 'auth.login';

    public const string ACTION_LOCKOUT = 'auth.lockout';

    public const string ACTION_LOGOUT = 'auth.logout';

    public const string ACTION_PASSWORD_CHANGED = 'auth.password_changed';

    public const string ACTION_PASSWORD_FORCED_CHANGE = 'auth.password_forced_change';

    public const string ACTION_BOOTSTRAP_CREATED = 'auth.bootstrap_created';

    public const string ACTION_BOOTSTRAP_REARMED = 'auth.bootstrap_rearmed';

    public const string ENTITY_USERS = 'users';

    /**
     * Keys removed from before and after, case-insensitively, as whole names.
     *
     * @var list<string>
     */
    private const array SECRET_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'temporary_password',
        'temp_password',
        'token',
        'reset_token',
        'remember_token',
        'password_hash',
        'hash',
        'secret',
        'secrets',
        'api_key',
        'brevo_api_key',
        'body_html',
        'body_text',
        'authorization',
    ];

    /**
     * Snapshots are walked to this depth. Anything nested further is dropped.
     */
    private const int MAX_DEPTH = 8;

    /**
     * Strip secrets and insert one row.
     *
     * Throws when no transaction is open. Does not start one. The arrays
     * passed in are not modified.
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
        if (DB::transactionLevel() < 1) {
            throw new AuditTransactionException;
        }

        return AuditLog::query()->create([
            'actor_id' => $actor_id,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entity_id,
            'before' => $before === null ? null : $this->stripSecrets($before),
            'after' => $after === null ? null : $this->stripSecrets($after),
            'ip' => $ip,
        ]);
    }

    /**
     * Copy a snapshot without secret keys.
     *
     * Depth 1 is the snapshot itself. A secret key at depth 8 or less is
     * dropped. An array value at depth 8 is dropped, because its contents
     * are past the cap. The input array is not written to.
     *
     * @param  array<int|string, mixed>  $snapshot
     * @return array<int|string, mixed>
     */
    private function stripSecrets(array $snapshot, int $depth = 1): array
    {
        $clean = [];

        foreach ($snapshot as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                if ($depth >= self::MAX_DEPTH) {
                    continue;
                }

                $clean[$key] = $this->stripSecrets($value, $depth + 1);

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
