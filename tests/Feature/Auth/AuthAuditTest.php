<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\PasswordService;
use App\Services\SuperAdminBootstrapResult;
use App\Services\SuperAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Thrown after an audit insert so the caller's transaction can roll back.
 */
final class AuditRollbackProbe extends RuntimeException {}

/**
 * Fixture password for auth audit tests. It stays in this file.
 */
function audit_password(): string
{
    return 'Portal-pass-1';
}

/**
 * A replacement password that passes the password rules.
 */
function audit_new_password(): string
{
    return 'River-lamp-29';
}

/**
 * A student who can sign in with the fixture password.
 */
function audit_student(string $email, string $matric_no, array $user_attributes = []): Student
{
    $user = User::factory()->create(array_merge([
        'email' => $email,
        'password' => audit_password(),
        'status' => UserStatus::Active,
        'must_change_password' => false,
        'temp_password_expires_at' => null,
        'last_login_at' => null,
    ], $user_attributes));

    return Student::factory()->create([
        'user_id' => $user->id,
        'matric_no' => $matric_no,
    ]);
}

/**
 * Post the login form from one socket address.
 */
function audit_post_login(string $identifier, string $password, string $address, ?string $forwarded_for = null): TestResponse
{
    $pending = test()->withServerVariables([
        'REMOTE_ADDR' => $address,
    ]);

    if ($forwarded_for !== null) {
        $pending = $pending->withHeader('X-Forwarded-For', $forwarded_for);
    }

    return $pending->post('/login', [
        'identifier' => $identifier,
        'password' => $password,
    ]);
}

/**
 * A request with a started session and a socket address.
 */
function audit_request(string $address): Request
{
    $request = Request::create('/login', 'POST');
    $request->server->set('REMOTE_ADDR', $address);
    $session = app('session')->driver();
    $session->start();
    $request->setLaravelSession($session);

    return $request;
}

/**
 * HMAC the login limiter would store for this identifier.
 */
function audit_identifier_hmac(string $normalised): string
{
    return hash_hmac('sha256', $normalised, (string) config('app.key'));
}

/**
 * Set or clear the bootstrap variables for one test.
 */
function audit_set_bootstrap_env(?string $email, ?string $hash): void
{
    $values = [
        'BOOTSTRAP_SUPER_ADMIN_EMAIL' => $email,
        'BOOTSTRAP_SUPER_ADMIN_PASSWORD_HASH' => $hash,
    ];

    foreach ($values as $name => $value) {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            continue;
        }

        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

/**
 * Whether any audit row contains this value.
 */
function audit_rows_contain(string $needle): bool
{
    if ($needle === '') {
        return false;
    }

    $encoded = json_encode(DB::table('audit_logs')->get()->all());

    return is_string($encoded) && str_contains($encoded, $needle);
}

/**
 * Fail the next audit insert after it has been written.
 */
function audit_fail_after_insert(bool &$inserted): void
{
    AuditLog::created(function () use (&$inserted): void {
        $inserted = true;

        throw new AuditRollbackProbe('forced failure after audit');
    });
}

afterEach(function (): void {
    audit_set_bootstrap_env(null, null);
});

it('writes one auth.login row', function () {
    $email = 'audit-login@example.test';
    $matric_no = 'CSC/2026/9101';
    $student = audit_student($email, $matric_no);

    audit_post_login($email, audit_password(), '203.0.113.10', '198.51.100.10')
        ->assertRedirect('/student');

    $row = AuditLog::query()->where('action', AuditService::ACTION_LOGIN)->first();
    $ip = (string) ($row?->ip);
    $leaked = audit_rows_contain($email)
        || audit_rows_contain($matric_no)
        || audit_rows_contain(audit_password())
        || audit_rows_contain(audit_identifier_hmac(strtolower($email)));

    expect(AuditLog::query()->where('action', AuditService::ACTION_LOGIN)->count())->toBe(1)
        ->and($row?->actor_id)->toBe($student->user_id)
        ->and($row?->entity)->toBe(AuditService::ENTITY_USERS)
        ->and($row?->entity_id)->toBe($student->user_id)
        ->and($row?->before)->toBe(['last_login_at' => null])
        ->and(is_string($row?->after['last_login_at'] ?? null))->toBeTrue()
        ->and($ip === '203.0.113.10')->toBeTrue()
        ->and($ip === '198.51.100.10')->toBeFalse()
        ->and($leaked)->toBeFalse()
        ->and($student->user->fresh()->last_login_at)->not->toBeNull();
});

it('writes one auth.logout row', function () {
    $email = 'audit-logout@example.test';
    $matric_no = 'CSC/2026/9102';
    $student = audit_student($email, $matric_no);

    $this->actingAs($student->user)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
        ->post('/logout')
        ->assertRedirect('/login');

    $row = AuditLog::query()->where('action', AuditService::ACTION_LOGOUT)->first();
    $leaked = audit_rows_contain($email) || audit_rows_contain($matric_no);

    expect(AuditLog::query()->count())->toBe(1)
        ->and($row?->actor_id)->toBe($student->user_id)
        ->and($row?->entity_id)->toBe($student->user_id)
        ->and($row?->ip)->toBe('203.0.113.20')
        ->and($leaked)->toBeFalse();
});

it('writes one auth.password_changed row', function () {
    $email = 'audit-change@example.test';
    $matric_no = 'CSC/2026/9103';
    $student = audit_student($email, $matric_no);
    $new_password = audit_new_password();

    $this->actingAs($student->user)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
        ->post('/change-password', [
            'current_password' => audit_password(),
            'password' => $new_password,
            'password_confirmation' => $new_password,
        ])
        ->assertRedirect('/student');

    $changed = Hash::check($new_password, (string) $student->user->fresh()->password);
    $leaked = audit_rows_contain($email)
        || audit_rows_contain($matric_no)
        || audit_rows_contain(audit_password())
        || audit_rows_contain($new_password);

    expect(AuditLog::query()->where('action', AuditService::ACTION_PASSWORD_CHANGED)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditService::ACTION_PASSWORD_FORCED_CHANGE)->count())->toBe(0)
        ->and(AuditLog::query()->first()?->actor_id)->toBe($student->user_id)
        ->and(AuditLog::query()->first()?->before)->toBe(['must_change_password' => false])
        ->and(AuditLog::query()->first()?->after)->toBe(['must_change_password' => false])
        ->and($changed)->toBeTrue()
        ->and($leaked)->toBeFalse();
});

it('writes one auth.password_forced_change row', function () {
    $email = 'audit-forced@example.test';
    $matric_no = 'CSC/2026/9104';
    $student = audit_student($email, $matric_no, [
        'must_change_password' => true,
        'temp_password_expires_at' => now()->addDays(7),
    ]);
    $new_password = audit_new_password();

    $this->actingAs($student->user)
        ->post('/change-password', [
            'current_password' => audit_password(),
            'password' => $new_password,
            'password_confirmation' => $new_password,
        ])
        ->assertRedirect('/student');

    $fresh = $student->user->fresh();
    $changed = Hash::check($new_password, (string) $fresh?->password);
    $leaked = audit_rows_contain($email)
        || audit_rows_contain($matric_no)
        || audit_rows_contain(audit_password())
        || audit_rows_contain($new_password);

    expect(AuditLog::query()->where('action', AuditService::ACTION_PASSWORD_FORCED_CHANGE)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditService::ACTION_PASSWORD_CHANGED)->count())->toBe(0)
        ->and(AuditLog::query()->first()?->before)->toBe(['must_change_password' => true])
        ->and(AuditLog::query()->first()?->after)->toBe(['must_change_password' => false])
        ->and($fresh?->must_change_password)->toBeFalse()
        ->and($fresh?->temp_password_expires_at)->toBeNull()
        ->and($changed)->toBeTrue()
        ->and($leaked)->toBeFalse();
});

it('writes one auth.bootstrap_created row', function () {
    $email = 'audit-bootstrap@example.test';
    $password = audit_password();
    $hash = Hash::make($password);
    audit_set_bootstrap_env($email, $hash);

    $this->artisan('create-super-admin --no-interaction')->assertSuccessful();

    $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
    $row = AuditLog::query()->where('action', AuditService::ACTION_BOOTSTRAP_CREATED)->first();
    $leaked = audit_rows_contain($email)
        || audit_rows_contain($password)
        || audit_rows_contain($hash);

    expect(AuditLog::query()->count())->toBe(1)
        ->and($row?->actor_id)->toBeNull()
        ->and($row?->entity)->toBe(AuditService::ENTITY_USERS)
        ->and($row?->entity_id)->toBe($user?->id)
        ->and($row?->ip)->toBeNull()
        ->and($row?->after['status'] ?? null)->toBe(UserStatus::Active->value)
        ->and($row?->after['must_change_password'] ?? null)->toBeTrue()
        ->and($row?->after['role'] ?? null)->toBe('SuperAdmin')
        ->and($leaked)->toBeFalse();
});

it('writes one auth.bootstrap_rearmed row', function () {
    $email = 'audit-rearm@example.test';
    $password = audit_password();
    $hash = Hash::make($password);
    audit_set_bootstrap_env($email, $hash);
    $this->artisan('create-super-admin --no-interaction')->assertSuccessful();

    $new_password = audit_new_password();
    $new_hash = Hash::make($new_password);
    audit_set_bootstrap_env($email, $new_hash);
    $this->artisan('create-super-admin --no-interaction')->assertSuccessful();

    $matches_new = Hash::check($new_password, (string) User::query()->whereRaw('lower(email) = ?', [$email])->value('password'));
    $rearmed = AuditLog::query()->where('action', AuditService::ACTION_BOOTSTRAP_REARMED)->first();
    $leaked = audit_rows_contain($email)
        || audit_rows_contain($password)
        || audit_rows_contain($new_password)
        || audit_rows_contain($hash)
        || audit_rows_contain($new_hash);

    expect(AuditLog::query()->where('action', AuditService::ACTION_BOOTSTRAP_CREATED)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditService::ACTION_BOOTSTRAP_REARMED)->count())->toBe(1)
        ->and($rearmed?->actor_id)->toBeNull()
        ->and($rearmed?->ip)->toBeNull()
        ->and($rearmed?->before['must_change_password'] ?? null)->toBeTrue()
        ->and($rearmed?->after['must_change_password'] ?? null)->toBeTrue()
        ->and($matches_new)->toBeTrue()
        ->and($leaked)->toBeFalse();
});

it('writes one lockout row on the tenth identifier failure', function () {
    $email = 'audit-lockout@example.test';
    $matric_no = 'CSC/2026/9105';
    $student = audit_student($email, $matric_no);

    for ($attempt = 1; $attempt <= 9; $attempt++) {
        audit_post_login($email, 'wrong-password-1', '203.0.113.'.$attempt)
            ->assertRedirect('/login');
    }

    expect(AuditLog::query()->count())->toBe(0);

    audit_post_login($email, 'wrong-password-1', '203.0.113.10', '198.51.100.10')
        ->assertRedirect('/login');

    $row = AuditLog::query()->where('action', AuditService::ACTION_LOCKOUT)->first();
    $ip = (string) ($row?->ip);

    audit_post_login($email, audit_password(), '203.0.113.11', '198.51.100.11')
        ->assertRedirect('/login');

    $leaked = audit_rows_contain($email)
        || audit_rows_contain($matric_no)
        || audit_rows_contain(audit_password())
        || audit_rows_contain('wrong-password-1')
        || audit_rows_contain(audit_identifier_hmac(strtolower($email)))
        || audit_rows_contain('198.51.100.10');

    expect(AuditLog::query()->count())->toBe(1)
        ->and($row?->actor_id)->toBeNull()
        ->and($row?->entity_id)->toBe($student->user_id)
        ->and($row?->before)->toBeNull()
        ->and($row?->after)->toBeNull()
        ->and($ip === '203.0.113.10')->toBeTrue()
        ->and($leaked)->toBeFalse();
});

it('writes no audit row for ten failures of an unknown identifier', function () {
    $email = 'audit-missing@example.test';

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        audit_post_login($email, 'wrong-password-1', '198.51.100.'.$attempt)
            ->assertRedirect('/login');
    }

    $locked = RateLimiter::tooManyAttempts('login-identifier:'.audit_identifier_hmac(strtolower($email)), 10);
    $leaked = audit_rows_contain($email) || audit_rows_contain(audit_identifier_hmac(strtolower($email)));

    expect($locked)->toBeTrue()
        ->and(AuditLog::query()->count())->toBe(0)
        ->and($leaked)->toBeFalse();
});

it('writes no lockout row for the pair limit alone', function () {
    $email = 'audit-pair@example.test';
    audit_student($email, 'CSC/2026/9106');

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        audit_post_login($email, 'wrong-password-1', '203.0.113.60')
            ->assertRedirect('/login');
    }

    audit_post_login($email, audit_password(), '203.0.113.60')
        ->assertRedirect('/login');

    expect(AuditLog::query()->count())->toBe(0);
});

it('rolls back the login and the audit row together', function () {
    $email = 'audit-login-rollback@example.test';
    $student = audit_student($email, 'CSC/2026/9107');
    $inserted = false;
    audit_fail_after_insert($inserted);

    $threw = false;

    try {
        app(AuthService::class)->attempt(audit_request('203.0.113.70'), $email, audit_password());
    } catch (AuditRollbackProbe) {
        $threw = true;
    }

    expect($threw)->toBeTrue()
        ->and($inserted)->toBeTrue()
        ->and($student->user->fresh()->last_login_at)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('rolls back a password change and the audit row together', function () {
    $student = audit_student('audit-change-rollback@example.test', 'CSC/2026/9108');
    $user = $student->user;
    $hash_before = (string) $user->password;
    $token_before = (string) $user->remember_token;
    $inserted = false;
    audit_fail_after_insert($inserted);
    $this->actingAs($user);

    $threw = false;

    try {
        app(PasswordService::class)->change(audit_request('203.0.113.71'), $user, audit_new_password());
    } catch (AuditRollbackProbe) {
        $threw = true;
    }

    $fresh = $user->fresh();
    $hash_same = hash_equals($hash_before, (string) $fresh?->password);
    $token_same = hash_equals($token_before, (string) $fresh?->remember_token);

    expect($threw)->toBeTrue()
        ->and($inserted)->toBeTrue()
        ->and($hash_same)->toBeTrue()
        ->and($token_same)->toBeTrue()
        ->and($fresh?->must_change_password)->toBeFalse()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('rolls back a forced password change and the audit row together', function () {
    $student = audit_student('audit-forced-rollback@example.test', 'CSC/2026/9109', [
        'must_change_password' => true,
        'temp_password_expires_at' => now()->addDays(7),
    ]);
    $user = $student->user;
    $hash_before = (string) $user->password;
    $expiry_before = $user->temp_password_expires_at?->toDateTimeString();
    $inserted = false;
    audit_fail_after_insert($inserted);
    $this->actingAs($user);

    $threw = false;

    try {
        app(PasswordService::class)->change(audit_request('203.0.113.72'), $user, audit_new_password());
    } catch (AuditRollbackProbe) {
        $threw = true;
    }

    $fresh = $user->fresh();
    $hash_same = hash_equals($hash_before, (string) $fresh?->password);
    $expiry_same = $expiry_before === $fresh?->temp_password_expires_at?->toDateTimeString();

    expect($threw)->toBeTrue()
        ->and($inserted)->toBeTrue()
        ->and($hash_same)->toBeTrue()
        ->and($expiry_same)->toBeTrue()
        ->and($fresh?->must_change_password)->toBeTrue()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('rolls back bootstrap creation and the audit row together', function () {
    $email = 'audit-bootstrap-rollback@example.test';
    $hash = Hash::make(audit_password());
    $inserted = false;
    audit_fail_after_insert($inserted);

    $result = app(SuperAdminService::class)->apply($email, 'Super Admin', $hash);
    $exists = User::query()->whereRaw('lower(email) = ?', [$email])->exists();

    expect($result)->toBe(SuperAdminBootstrapResult::Failed)
        ->and($inserted)->toBeTrue()
        ->and($exists)->toBeFalse()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('rolls back a bootstrap re-arm and the audit row together', function () {
    $email = 'audit-rearm-rollback@example.test';
    $password = audit_password();
    $hash = Hash::make($password);
    $created = app(SuperAdminService::class)->apply($email, 'Super Admin', $hash);

    $new_password = audit_new_password();
    $new_hash = Hash::make($new_password);
    $inserted = false;
    audit_fail_after_insert($inserted);
    $result = app(SuperAdminService::class)->apply($email, 'Super Admin', $new_hash);

    $stored = (string) User::query()->whereRaw('lower(email) = ?', [$email])->value('password');
    $old_matches = Hash::check($password, $stored);
    $new_matches = Hash::check($new_password, $stored);

    expect($created)->toBe(SuperAdminBootstrapResult::Created)
        ->and($result)->toBe(SuperAdminBootstrapResult::Failed)
        ->and($inserted)->toBeTrue()
        ->and($old_matches)->toBeTrue()
        ->and($new_matches)->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditService::ACTION_BOOTSTRAP_REARMED)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditService::ACTION_BOOTSTRAP_CREATED)->count())->toBe(1);
});

it('rolls back logout\'s audit row with its transaction', function () {
    $student = audit_student('audit-logout-rollback@example.test', 'CSC/2026/9110');
    $request = audit_request('203.0.113.73');
    $this->actingAs($student->user);
    $request->setUserResolver(fn (): User => $student->user);
    $inserted = false;
    audit_fail_after_insert($inserted);

    $threw = false;

    try {
        app(AuthService::class)->logout($request);
    } catch (AuditRollbackProbe) {
        $threw = true;
    }

    expect($threw)->toBeTrue()
        ->and($inserted)->toBeTrue()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('rolls back a lockout audit row with its transaction', function () {
    $email = 'audit-lockout-rollback@example.test';
    $student = audit_student($email, 'CSC/2026/9111');
    $hash_before = (string) $student->user->password;

    for ($attempt = 1; $attempt <= 9; $attempt++) {
        app(AuthService::class)->attempt(audit_request('203.0.113.'.(80 + $attempt)), $email, 'wrong-password-1');
    }

    $inserted = false;
    audit_fail_after_insert($inserted);
    $threw = false;

    try {
        app(AuthService::class)->attempt(audit_request('203.0.113.90'), $email, 'wrong-password-1');
    } catch (AuditRollbackProbe) {
        $threw = true;
    }

    $hash_same = hash_equals($hash_before, (string) $student->user->fresh()->password);

    expect($threw)->toBeTrue()
        ->and($inserted)->toBeTrue()
        ->and($hash_same)->toBeTrue()
        ->and($student->user->fresh()->last_login_at)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
});
