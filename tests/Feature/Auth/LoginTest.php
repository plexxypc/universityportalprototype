<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Fixture password. It stays in this file and must not be written to logs.
 */
function login_fixture_password(): string
{
    return 'Portal-pass-1';
}

/**
 * A student account that can sign in with the fixture password.
 */
function login_student(array $user_attributes = [], array $student_attributes = []): Student
{
    $user = User::factory()->create(array_merge([
        'password' => login_fixture_password(),
        'status' => UserStatus::Active,
    ], $user_attributes));

    return Student::factory()->create(array_merge([
        'user_id' => $user->id,
        'matric_no' => 'CSC/2026/0001',
    ], $student_attributes));
}

/**
 * A staff account that can sign in with the fixture password.
 */
function login_staff(Role $role = Role::Registrar, array $user_attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'email' => 'registrar@example.com',
        'password' => login_fixture_password(),
        'status' => UserStatus::Active,
    ], $user_attributes));

    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    return $user;
}

/**
 * Post the login form.
 */
function post_login(string $identifier, string $password): TestResponse
{
    return test()->post('/login', [
        'identifier' => $identifier,
        'password' => $password,
    ]);
}

/**
 * Require the attempt to call Hash::check once, and run the real check.
 */
function expect_one_password_check(callable $attempt): void
{
    $hasher = app('hash')->driver();

    Hash::partialMock()
        ->shouldReceive('check')
        ->once()
        ->andReturnUsing(function (string $plain, string $hashed) use ($hasher): bool {
            return $hasher->check($plain, $hashed);
        });

    $attempt();
}

beforeEach(function (): void {
    $this->withoutVite();
});

it('signs a student in with a matric number', function () {
    login_student();

    post_login('CSC/2026/0001', login_fixture_password())
        ->assertRedirect('/student');

    $this->assertAuthenticated();
    $this->get('/student')->assertOk()->assertSee('Student portal');
});

it('accepts a matric number with different case and spacing', function () {
    login_student();

    post_login('  csc / 2026 / 0001  ', login_fixture_password())
        ->assertRedirect('/student');

    $this->assertAuthenticated();
});

it('signs a student in with an email address', function () {
    login_student([
        'email' => 'Ada@Example.com',
    ]);

    post_login('ada@example.com', login_fixture_password())
        ->assertRedirect('/student');

    $this->assertAuthenticated();
});

it('sends staff to the staff panel', function () {
    $staff = login_staff();

    post_login('registrar@example.com', login_fixture_password())
        ->assertRedirect('/staff');

    $this->assertAuthenticatedAs($staff);
    $this->get('/staff')->assertOk();
});

it('sends a user who is staff and a student to the staff panel', function () {
    $staff = login_staff();
    Student::factory()->create([
        'user_id' => $staff->id,
        'matric_no' => 'CSC/2026/0002',
    ]);

    post_login('registrar@example.com', login_fixture_password())
        ->assertRedirect('/staff');

    $this->get('/student')->assertOk();
});

it('lets an active staff user open the panel when the environment is production', function () {
    $staff = login_staff();

    config(['app.env' => 'production']);

    $this->actingAs($staff)->get('/staff')->assertOk();
});

it('changes the session id after login', function () {
    login_student();

    $this->get('/login');
    $before = session()->getId();

    post_login('CSC/2026/0001', login_fixture_password())
        ->assertRedirect('/student');

    expect(session()->getId())->not->toBe($before);
});

it('sets last login at on success', function () {
    $student = login_student();

    post_login('CSC/2026/0001', login_fixture_password())->assertRedirect('/student');

    expect($student->user->refresh()->last_login_at)->not->toBeNull();
});

it('rejects a wrong password', function () {
    login_student();

    post_login('CSC/2026/0001', 'wrong-password-1')
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['identifier' => AuthService::FAILURE_MESSAGE]);

    $this->assertGuest();
});

it('rejects an unknown identifier', function () {
    post_login('nobody@example.com', login_fixture_password())
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['identifier' => AuthService::FAILURE_MESSAGE]);

    $this->assertGuest();
});

it('rejects a deactivated user', function () {
    login_student([
        'status' => UserStatus::Deactivated,
    ]);

    post_login('CSC/2026/0001', login_fixture_password())
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['identifier' => AuthService::FAILURE_MESSAGE]);

    $this->assertGuest();
});

it('rejects a suspended user', function () {
    login_student([
        'status' => UserStatus::Suspended,
    ]);

    post_login('CSC/2026/0001', login_fixture_password())
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['identifier' => AuthService::FAILURE_MESSAGE]);

    $this->assertGuest();
});

it('blocks login when the temporary password has expired', function () {
    login_student([
        'must_change_password' => true,
        'temp_password_expires_at' => now()->subMinute(),
    ]);

    post_login('CSC/2026/0001', login_fixture_password())
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['identifier' => AuthService::FAILURE_MESSAGE]);

    $this->assertGuest();
});

it('signs in when the temporary password has not expired', function () {
    login_student([
        'must_change_password' => true,
        'temp_password_expires_at' => now()->addDay(),
    ]);

    post_login('CSC/2026/0001', login_fixture_password())
        ->assertRedirect('/student');

    $this->assertAuthenticated();
});

it('signs out a user who has no role', function () {
    $user = User::factory()->create([
        'email' => 'norole@example.com',
        'password' => login_fixture_password(),
        'status' => UserStatus::Active,
    ]);

    post_login('norole@example.com', login_fixture_password())
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['identifier' => AuthService::FAILURE_MESSAGE]);

    $this->assertGuest();
    expect($user->refresh()->last_login_at)->toBeNull();
});

it('uses the same message and status for every failed sign-in', function () {
    login_student([
        'email' => 'active@example.com',
    ]);
    login_student([
        'email' => 'deactivated@example.com',
        'status' => UserStatus::Deactivated,
    ], [
        'matric_no' => 'CSC/2026/0002',
    ]);
    login_student([
        'email' => 'suspended@example.com',
        'status' => UserStatus::Suspended,
    ], [
        'matric_no' => 'CSC/2026/0003',
    ]);
    login_student([
        'email' => 'expired@example.com',
        'must_change_password' => true,
        'temp_password_expires_at' => now()->subMinute(),
    ], [
        'matric_no' => 'CSC/2026/0004',
    ]);
    User::factory()->create([
        'email' => 'norole@example.com',
        'password' => login_fixture_password(),
    ]);

    $attempts = [
        ['CSC/2026/0001', 'wrong-password-1'],
        ['missing@example.com', login_fixture_password()],
        ['CSC/2026/0002', login_fixture_password()],
        ['CSC/2026/0003', login_fixture_password()],
        ['CSC/2026/0004', login_fixture_password()],
        ['norole@example.com', login_fixture_password()],
    ];

    foreach ($attempts as [$identifier, $password]) {
        post_login($identifier, $password)
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['identifier' => AuthService::FAILURE_MESSAGE]);
    }
});

it('checks the password once for an unknown user', function () {
    expect_one_password_check(function (): void {
        post_login('nobody@example.com', login_fixture_password())
            ->assertRedirect('/login');
    });
});

it('checks the password once for a wrong password', function () {
    login_student();

    expect_one_password_check(function (): void {
        post_login('CSC/2026/0001', 'wrong-password-1')
            ->assertRedirect('/login');
    });
});

it('checks the password once for a deactivated user', function () {
    login_student([
        'status' => UserStatus::Deactivated,
    ]);

    expect_one_password_check(function (): void {
        post_login('CSC/2026/0001', login_fixture_password())
            ->assertRedirect('/login');
    });
});

it('checks the password once for a suspended user', function () {
    login_student([
        'status' => UserStatus::Suspended,
    ]);

    expect_one_password_check(function (): void {
        post_login('CSC/2026/0001', login_fixture_password())
            ->assertRedirect('/login');
    });
});

it('checks the password once for a user with no role', function () {
    User::factory()->create([
        'email' => 'norole@example.com',
        'password' => login_fixture_password(),
    ]);

    expect_one_password_check(function (): void {
        post_login('norole@example.com', login_fixture_password())
            ->assertRedirect('/login');
    });
});

it('checks the password once for an expired temporary password', function () {
    login_student([
        'must_change_password' => true,
        'temp_password_expires_at' => now()->subMinute(),
    ]);

    expect_one_password_check(function (): void {
        post_login('CSC/2026/0001', login_fixture_password())
            ->assertRedirect('/login');
    });
});

it('keeps the password out of the session and the logs', function () {
    login_student();

    $leaked = false;
    Log::listen(function (MessageLogged $event) use (&$leaked): void {
        $payload = $event->message.' '.json_encode($event->context);

        if (str_contains($payload, 'wrong-password-1') || str_contains($payload, login_fixture_password())) {
            $leaked = true;
        }
    });

    $failed = post_login('CSC/2026/0001', 'wrong-password-1');
    $failed->assertRedirect('/login');

    expect(session('_old_input.password'))->toBeNull();
    expect($failed->getContent())->not->toContain('wrong-password-1');
    expect(json_encode(session()->all()))->not->toContain('wrong-password-1');

    $invalid = post_login('', login_fixture_password());
    $invalid->assertSessionHasErrors('identifier');

    expect(session('_old_input.password'))->toBeNull();
    expect($invalid->getContent())->not->toContain(login_fixture_password());
    expect(json_encode(session()->all()))->not->toContain(login_fixture_password());
    expect($leaked)->toBeFalse();
});

it('shows the login form with the password toggle', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Matric number or email')
        ->assertSee('type="password"', false)
        ->assertSee('Show password')
        ->assertSee(config('portal.institution.name'))
        ->assertDontSee('Forgot password')
        ->assertDontSee('Sign up');
});

it('stops a student from opening the staff panel', function () {
    $student = login_student();

    $response = $this->actingAs($student->user)->get('/staff');

    $response->assertRedirect('/student');
    $response->assertDontSee('Account');
    expect($response->getContent())->not->toContain('wire:snapshot');
});

it('sends a guest from the staff panel to the portal login', function () {
    $this->get('/staff')->assertRedirect('/login');
    $this->get('/staff/login')->assertRedirect('/login');
});

it('does not register filament login, registration, password reset, or profile', function () {
    $routes = collect(Route::getRoutes());
    $uris = $routes->map(fn ($route): string => $route->uri())->all();
    $names = $routes->map(fn ($route): ?string => $route->getName())->all();
    expect($names)->not->toContain('filament.staff.auth.login')
        ->and($names)->not->toContain('filament.staff.auth.register')
        ->and($names)->not->toContain('filament.staff.auth.password-reset.request')
        ->and($names)->not->toContain('filament.staff.auth.password-reset.reset')
        ->and($names)->not->toContain('filament.staff.auth.profile')
        ->and($names)->not->toContain('filament.staff.pages.profile')
        ->and($names)->toContain('staff.login')
        ->and($uris)->not->toContain('staff/register')
        ->and($uris)->not->toContain('staff/password-reset/request')
        ->and($uris)->not->toContain('staff/password-reset/reset')
        ->and($uris)->not->toContain('staff/email-verification/prompt')
        ->and($uris)->not->toContain('staff/profile');

    $this->get('/staff/register')->assertNotFound();
    $this->get('/staff/password-reset/request')->assertNotFound();
    $this->get('/staff/profile')->assertNotFound();
});

it('rejects an unauthenticated Livewire update inside the staff panel', function () {
    $staff = login_staff();

    $page = $this->actingAs($staff)->get('/staff');
    $page->assertOk();

    preg_match_all('/wire:snapshot="([^"]*)"/', $page->getContent(), $matches);
    expect($matches[1])->not->toBeEmpty();

    $snapshot = html_entity_decode($matches[1][0], ENT_QUOTES);

    $update = $this->actingAsGuest()
        ->withHeader('X-Livewire', 'true')
        ->postJson('/livewire/update', [
            'components' => [
                [
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [],
                ],
            ],
        ]);

    expect($update->status())->not->toBe(200);
    expect($update->getContent())->not->toContain('wire:snapshot');
    expect($update->getContent())->not->toContain('Account');
});
