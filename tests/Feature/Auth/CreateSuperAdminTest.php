<?php

declare(strict_types=1);

use App\Console\Commands\CreateSuperAdmin;
use App\Console\Commands\HashPassword;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Tester\CommandTester;

uses(RefreshDatabase::class);

/**
 * Fixture password for the bootstrap command. It stays in this file.
 */
function bootstrap_password(): string
{
    return 'Bootstrap-pass-1';
}

/**
 * Set or clear the two bootstrap variables.
 */
function bootstrap_set_env(?string $email, ?string $hash): void
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
 * Hash from the hidden prompt. The plaintext must not be in the output.
 */
function bootstrap_hash(string $password): string
{
    $command = app(HashPassword::class);
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->setInputs([$password, $password]);
    $exit_code = $tester->execute([], ['interactive' => true]);
    $output = $tester->getDisplay();

    expect($exit_code)->toBe(0);
    expect($output)->not->toContain($password);

    $matched = preg_match('/(\$2[aby]\$\d{2}\$[A-Za-z0-9.\/]{53})/', $output, $matches);

    expect($matched)->toBe(1);

    return $matches[1];
}

/**
 * Fail the test if a log record contains the password or the hash.
 */
function bootstrap_watch_logs(string $password, string $hash): void
{
    Log::listen(function (MessageLogged $event) use ($password, $hash): void {
        $encoded = $event->message.' '.json_encode($event->context);

        expect($encoded)->not->toContain($password)->and($encoded)->not->toContain($hash);
    });
}

afterEach(function (): void {
    bootstrap_set_env(null, null);
    Carbon::setTestNow();
});

it('has no password argument on either bootstrap command', function () {
    $create = Artisan::all()['create-super-admin']->getDefinition();
    $hash = Artisan::all()['portal:hash-password']->getDefinition();

    expect($create->getArguments())->toBeEmpty()
        ->and($create->hasOption('password'))->toBeFalse()
        ->and($hash->getArguments())->toBeEmpty()
        ->and($hash->hasOption('password'))->toBeFalse();
});

it('creates an admin from a hash and signs in with the original password', function () {
    Carbon::setTestNow('2026-10-10 12:00:00');

    $password = bootstrap_password();
    $hash = bootstrap_hash($password);
    bootstrap_set_env('admin@example.test', $hash);
    bootstrap_watch_logs($password, $hash);

    $this->artisan('create-super-admin --no-interaction')
        ->expectsOutputToContain('Super Admin created.')
        ->expectsOutputToContain(CreateSuperAdmin::REMINDER)
        ->doesntExpectOutputToContain($password)
        ->doesntExpectOutputToContain($hash)
        ->assertSuccessful();

    $user = User::query()->where('email', 'admin@example.test')->first();
    $assignment = $user?->roleAssignments()->first();

    expect($user)->not->toBeNull()
        ->and($user?->name)->toBe('Super Admin')
        ->and($user?->status)->toBe(UserStatus::Active)
        ->and($user?->must_change_password)->toBeTrue()
        ->and($user?->temp_password_expires_at?->equalTo(now()->addHours(24)))->toBeTrue()
        ->and($assignment)->not->toBeNull()
        ->and($assignment?->role)->toBe(Role::SuperAdmin)
        ->and($assignment?->faculty_id)->toBeNull()
        ->and($assignment?->department_id)->toBeNull()
        ->and(Hash::check($password, (string) $user?->password))->toBeTrue();

    $request = Request::create('/login', 'POST');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');
    $session = app('session')->driver();
    $session->start();
    $request->setLaravelSession($session);

    $signed_in = app(AuthService::class)->attempt($request, 'admin@example.test', $password);
    $rejected = app(AuthService::class)->attempt($request, 'admin@example.test', 'Wrong-pass-9');

    expect($signed_in->succeeded)->toBeTrue()
        ->and($signed_in->redirect_to)->toBe('/staff')
        ->and($rejected->succeeded)->toBeFalse();
});

it('rejects a duplicate email without printing the password', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    $password = bootstrap_password();
    $hash = bootstrap_hash($password);
    bootstrap_set_env('taken@example.test', $hash);
    bootstrap_watch_logs($password, $hash);

    $this->artisan('create-super-admin --no-interaction')
        ->expectsOutputToContain('A user with that email already exists. Nothing was created.')
        ->doesntExpectOutputToContain($password)
        ->doesntExpectOutputToContain($hash)
        ->assertFailed();

    expect(RoleAssignment::query()->where('role', Role::SuperAdmin)->exists())->toBeFalse();
});

it('refreshes the bootstrap admin until the password has been changed', function () {
    Carbon::setTestNow('2026-10-10 12:00:00');

    $hash = bootstrap_hash(bootstrap_password());
    bootstrap_set_env('admin@example.test', $hash);
    $this->artisan('create-super-admin --no-interaction')->assertSuccessful();

    Carbon::setTestNow('2026-10-10 20:00:00');

    $second = 'Bootstrap-pass-2';
    $second_hash = bootstrap_hash($second);
    bootstrap_set_env('admin@example.test', $second_hash);
    bootstrap_watch_logs($second, $second_hash);

    $this->artisan('create-super-admin --no-interaction')
        ->expectsOutputToContain('temporary password was refreshed.')
        ->expectsOutputToContain(CreateSuperAdmin::REMINDER)
        ->doesntExpectOutputToContain($second)
        ->doesntExpectOutputToContain($second_hash)
        ->assertSuccessful();

    $user = User::query()->where('email', 'admin@example.test')->firstOrFail();

    expect($user->must_change_password)->toBeTrue()
        ->and($user->temp_password_expires_at?->equalTo(now()->addHours(24)))->toBeTrue()
        ->and(Hash::check($second, (string) $user->password))->toBeTrue()
        ->and(Hash::check(bootstrap_password(), (string) $user->password))->toBeFalse();

    $user->forceFill([
        'must_change_password' => false,
        'temp_password_expires_at' => null,
    ])->save();
    $stored = $user->password;

    $third = 'Bootstrap-pass-3';
    $third_hash = bootstrap_hash($third);
    bootstrap_set_env('admin@example.test', $third_hash);

    $this->artisan('create-super-admin --no-interaction')
        ->expectsOutputToContain('A Super Admin already exists. Nothing was created.')
        ->expectsOutputToContain(CreateSuperAdmin::REMINDER)
        ->doesntExpectOutputToContain($third)
        ->doesntExpectOutputToContain($third_hash)
        ->assertSuccessful();
    $user->refresh();

    expect($user->password)->toBe($stored)
        ->and($user->must_change_password)->toBeFalse()
        ->and($user->temp_password_expires_at)->toBeNull();
});

it('treats a deactivated super admin as an existing super admin', function () {
    $existing = User::factory()->deactivated()->create([
        'email' => 'old@example.test',
        'must_change_password' => false,
    ]);

    RoleAssignment::factory()->create([
        'user_id' => $existing->id,
        'role' => Role::SuperAdmin,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    $password = bootstrap_password();
    $hash = bootstrap_hash($password);
    bootstrap_set_env('new@example.test', $hash);
    bootstrap_watch_logs($password, $hash);

    $this->artisan('create-super-admin --no-interaction')
        ->expectsOutputToContain('A Super Admin already exists. Nothing was created.')
        ->expectsOutputToContain(CreateSuperAdmin::REMINDER)
        ->doesntExpectOutputToContain($password)
        ->doesntExpectOutputToContain($hash)
        ->assertSuccessful();

    expect(User::query()->where('email', 'new@example.test')->exists())->toBeFalse();
});
