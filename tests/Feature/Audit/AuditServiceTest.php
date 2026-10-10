<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\AuditLogImmutableException;
use App\Exceptions\AuditTransactionException;
use App\Models\AuditLog;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/**
 * Public methods declared on AuditService.
 *
 * @return list<string>
 */
function audit_service_public_methods(): array
{
    $names = [];

    foreach ((new ReflectionClass(AuditService::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== AuditService::class) {
            continue;
        }

        $names[] = $method->name;
    }

    sort($names);

    return $names;
}

/**
 * Whether the encoded snapshot still holds this secret.
 */
function audit_snapshot_contains(?array $snapshot, string $secret): bool
{
    $encoded = json_encode($snapshot);

    return is_string($encoded) && str_contains($encoded, $secret);
}

it('inserts one audit row and exposes no update or delete method', function () {
    $user = User::factory()->create();

    $log = DB::transaction(fn (): AuditLog => app(AuditService::class)->record(
        actor_id: $user->id,
        action: AuditService::ACTION_LOGIN,
        entity: AuditService::ENTITY_USERS,
        entity_id: $user->id,
        before: ['last_login_at' => null],
        after: ['last_login_at' => '2026-10-10 18:00:00'],
        ip: '203.0.113.10',
    ));

    $log->refresh();

    expect(audit_service_public_methods())->toBe(['record'])
        ->and($log->actor_id)->toBe($user->id)
        ->and($log->action)->toBe(AuditService::ACTION_LOGIN)
        ->and($log->entity)->toBe(AuditService::ENTITY_USERS)
        ->and($log->entity_id)->toBe($user->id)
        ->and($log->before)->toBe(['last_login_at' => null])
        ->and($log->after)->toBe(['last_login_at' => '2026-10-10 18:00:00'])
        ->and($log->ip)->toBe('203.0.113.10')
        ->and($log->created_at)->not->toBeNull()
        ->and(AuditLog::query()->count())->toBe(1);

    $update_rejected = false;

    try {
        $log->update(['action' => AuditService::ACTION_LOGOUT]);
    } catch (AuditLogImmutableException) {
        $update_rejected = true;
    }

    $delete_rejected = false;

    try {
        $log->delete();
    } catch (AuditLogImmutableException) {
        $delete_rejected = true;
    }

    expect($update_rejected)->toBeTrue()
        ->and($delete_rejected)->toBeTrue()
        ->and(AuditLog::query()->whereKey($log->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->whereKey($log->id)->value('action'))->toBe(AuditService::ACTION_LOGIN);
});

it('removes nested secrets and leaves the original arrays unchanged', function () {
    $top_secret = 'top-secret-value';
    $nested_secret = 'nested-secret-value';
    $deep_secret = 'deep-secret-value';
    $after_secret = 'after-secret-value';

    $before = [
        'Password' => $top_secret,
        'note' => 'kept-root',
        'level2' => [
            'level3' => [
                'password' => $nested_secret,
                'label' => 'kept-nested',
                'level4' => [
                    'level5' => [
                        'level6' => [
                            'level7' => [
                                'level8' => [
                                    'kept_scalar' => 'visible-at-depth-8',
                                    'nested' => [
                                        'label' => 'dropped-past-depth',
                                        'password' => $deep_secret,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
    $after = [
        'authorization' => $after_secret,
        'status' => 'Active',
    ];

    $log = DB::transaction(fn (): AuditLog => app(AuditService::class)->record(
        actor_id: null,
        action: AuditService::ACTION_LOGIN,
        entity: AuditService::ENTITY_USERS,
        entity_id: null,
        before: $before,
        after: $after,
        ip: null,
    ));

    $log->refresh();

    $before_leaked = audit_snapshot_contains($log->before, $top_secret)
        || audit_snapshot_contains($log->before, $nested_secret)
        || audit_snapshot_contains($log->before, $deep_secret);
    $after_leaked = audit_snapshot_contains($log->after, $after_secret);
    $original_intact = ($before['Password'] ?? null) === $top_secret
        && (($before['level2']['level3']['password'] ?? null) === $nested_secret)
        && (($before['level2']['level3']['level4']['level5']['level6']['level7']['level8']['nested']['password'] ?? null) === $deep_secret)
        && (($after['authorization'] ?? null) === $after_secret);
    $deep_dropped = data_get($log->before, 'level2.level3.level4.level5.level6.level7.level8.nested') === null;
    $password_key_removed = ! array_key_exists('Password', $log->before ?? [])
        && ! array_key_exists('password', $log->before['level2']['level3'] ?? []);

    expect($before_leaked)->toBeFalse()
        ->and($after_leaked)->toBeFalse()
        ->and($original_intact)->toBeTrue()
        ->and($deep_dropped)->toBeTrue()
        ->and($password_key_removed)->toBeTrue()
        ->and($log->before['note'] ?? null)->toBe('kept-root')
        ->and(data_get($log->before, 'level2.level3.label'))->toBe('kept-nested')
        ->and(data_get($log->before, 'level2.level3.level4.level5.level6.level7.level8.kept_scalar'))->toBe('visible-at-depth-8')
        ->and($log->after)->toBe(['status' => 'Active']);
});

it('removes a nested secrets key', function () {
    $secret = 'nested-secrets-payload';
    $before = [
        'label' => 'kept',
        'wrapper' => [
            'secrets' => [
                'temporary_password' => $secret,
            ],
            'note' => 'also-kept',
        ],
    ];

    $log = DB::transaction(fn (): AuditLog => app(AuditService::class)->record(
        actor_id: null,
        action: AuditService::ACTION_LOGIN,
        entity: AuditService::ENTITY_USERS,
        entity_id: null,
        before: $before,
        after: null,
        ip: null,
    ));

    $log->refresh();
    $leaked = audit_snapshot_contains($log->before, $secret);
    $key_removed = ! array_key_exists('secrets', $log->before['wrapper'] ?? []);

    expect($leaked)->toBeFalse()
        ->and($key_removed)->toBeTrue()
        ->and($log->before['label'] ?? null)->toBe('kept')
        ->and(data_get($log->before, 'wrapper.note'))->toBe('also-kept');
});

it('rolls the audit row back with the surrounding transaction', function () {
    $threw = false;

    try {
        DB::transaction(function (): void {
            app(AuditService::class)->record(
                actor_id: null,
                action: AuditService::ACTION_LOGOUT,
                entity: AuditService::ENTITY_USERS,
                entity_id: null,
                before: null,
                after: null,
                ip: '127.0.0.1',
            );

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException $exception) {
        $threw = $exception->getMessage() === 'roll back';
    }

    expect($threw)->toBeTrue()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('throws when record is called outside a transaction', function () {
    $connection = DB::connection();

    expect($connection->transactionLevel())->toBeGreaterThan(0);

    // RefreshDatabase keeps one transaction open for the whole test, so the
    // guard would not see level 0 until that wrapping transaction ends.
    $connection->rollBack();

    try {
        expect($connection->transactionLevel())->toBe(0)
            ->and($connection->getPdo()->inTransaction())->toBeFalse();

        $rejected = false;

        try {
            app(AuditService::class)->record(
                actor_id: null,
                action: AuditService::ACTION_LOGIN,
                entity: AuditService::ENTITY_USERS,
                entity_id: null,
                before: null,
                after: null,
                ip: null,
            );
        } catch (AuditTransactionException $exception) {
            $rejected = $exception->getMessage() === AuditTransactionException::MESSAGE;
        }

        expect($rejected)->toBeTrue()
            ->and(DB::table('audit_logs')->count())->toBe(0);
    } finally {
        if (DB::table('audit_logs')->count() > 0) {
            DB::table('audit_logs')->delete();
        }

        if ($connection->transactionLevel() === 0) {
            $connection->beginTransaction();
        }
    }
});

it('allows only audit_logs.view to read audit logs', function () {
    $log = AuditLog::factory()->create([
        'action' => AuditService::ACTION_LOGIN,
        'entity' => AuditService::ENTITY_USERS,
    ]);

    $admin = User::factory()->create(['status' => UserStatus::Active]);
    RoleAssignment::factory()->create([
        'user_id' => $admin->id,
        'role' => Role::SuperAdmin,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    $inactive = User::factory()->suspended()->create();
    RoleAssignment::factory()->create([
        'user_id' => $inactive->id,
        'role' => Role::SuperAdmin,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    $registrar = User::factory()->create(['status' => UserStatus::Active]);
    RoleAssignment::factory()->create([
        'user_id' => $registrar->id,
        'role' => Role::Registrar,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    $student = Student::factory()->create()->user;

    expect(Gate::forUser($admin)->allows('audit_logs.view'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('viewAny', AuditLog::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('view', $log))->toBeTrue()
        ->and(Gate::forUser($inactive)->allows('viewAny', AuditLog::class))->toBeFalse()
        ->and(Gate::forUser($registrar)->allows('audit_logs.view'))->toBeFalse()
        ->and(Gate::forUser($registrar)->allows('viewAny', AuditLog::class))->toBeFalse()
        ->and(Gate::forUser($registrar)->allows('view', $log))->toBeFalse()
        ->and(Gate::forUser($student)->allows('viewAny', AuditLog::class))->toBeFalse()
        ->and(Gate::forUser($student)->allows('view', $log))->toBeFalse();
});
