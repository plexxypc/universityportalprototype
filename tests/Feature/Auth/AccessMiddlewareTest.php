<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

uses(RefreshDatabase::class);

/**
 * Fixture password for these access tests. It stays in this file.
 */
function access_password(): string
{
    return 'Portal-pass-1';
}

/**
 * A student who can open /student.
 */
function access_student(array $user_attributes = [], array $student_attributes = []): Student
{
    $user = User::factory()->create(array_merge([
        'password' => access_password(),
        'status' => UserStatus::Active,
    ], $user_attributes));

    return Student::factory()->create(array_merge([
        'user_id' => $user->id,
    ], $student_attributes));
}

/**
 * A staff user who can open /staff.
 */
function access_staff(Role $role = Role::Registrar, array $user_attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'password' => access_password(),
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
 * Post one Livewire update using the snapshot from a rendered page.
 */
function access_livewire_update(string $html): TestResponse
{
    $matched = preg_match('/wire:snapshot="([^"]*)"/', $html, $matches);

    expect($matched)->toBe(1);

    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);

    return test()
        ->withHeader('X-Livewire', 'true')
        ->withHeader('X-CSRF-TOKEN', csrf_token())
        ->postJson(EndpointResolver::updatePath(), [
            'components' => [
                [
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [],
                ],
            ],
        ]);
}

it('sends a user who must change their password to the change page', function () {
    $student = access_student([
        'must_change_password' => true,
        'temp_password_expires_at' => now()->addDay(),
    ]);
    $staff = access_staff(user_attributes: [
        'must_change_password' => true,
        'temp_password_expires_at' => now()->addDay(),
    ]);

    $this->actingAs($student->user);

    $this->get('/student')->assertRedirect(route('password.edit'));
    $this->get('/student/ping')->assertRedirect(route('password.edit'));
    $this->get('/student/livewire-probe')->assertRedirect(route('password.edit'));

    $this->get(route('password.edit'))
        ->assertOk()
        ->assertSee('Change password');

    $this->post(route('logout'))
        ->assertRedirect(route('login'));
    $this->assertGuest();

    $this->actingAs($staff);
    $this->get('/staff')->assertRedirect(route('password.edit'));
    $this->get('/staff/downloads/ping')->assertRedirect(route('password.edit'));
    expect($this->get('/staff')->getContent())->not->toContain('wire:snapshot');
});

it('allows the build directory, the favicon, and the livewire script only', function () {
    $student = access_student([
        'must_change_password' => true,
        'temp_password_expires_at' => now()->addDay(),
    ]);
    $middleware = app(EnsurePasswordChanged::class);
    $user = $student->user;

    $allowed = [
        '/build/app.css',
        '/build',
        '/favicon.ico',
        EndpointResolver::scriptPath(minified: false),
        EndpointResolver::scriptPath(minified: true),
    ];

    foreach ($allowed as $path) {
        $request = Request::create($path, 'GET');
        $request->setUserResolver(fn (): User => $user);
        $response = $middleware->handle($request, fn () => response('asset-ok'));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getContent())->toBe('asset-ok');
    }

    $blocked = [
        '/built/app.css',
        '/student/build/app.css',
        EndpointResolver::updatePath(),
        EndpointResolver::prefix().'/upload-file',
    ];

    foreach ($blocked as $path) {
        $request = Request::create($path, 'GET');
        $request->setUserResolver(fn (): User => $user);
        $response = $middleware->handle($request, fn () => response('asset-ok'));

        expect($response->isRedirect(route('password.edit')))->toBeTrue();
    }
});

it('rejects a student livewire update after the password flag is set', function () {
    $student = access_student();
    $this->actingAs($student->user);

    $page = $this->get('/student/livewire-probe');
    $page->assertOk()->assertSee('Student area probe');

    $student->user->forceFill([
        'must_change_password' => true,
        'temp_password_expires_at' => now()->addDay(),
    ])->save();

    $update = access_livewire_update($page->getContent());

    $update->assertRedirect(route('password.edit'));
    expect($update->getContent())->not->toContain('Student area probe');
    $this->assertAuthenticated();
});

it('signs out a deactivated student on the next livewire update', function () {
    $student = access_student();
    $this->actingAs($student->user);

    $page = $this->get('/student/livewire-probe')->assertOk();

    $student->user->forceFill(['status' => UserStatus::Deactivated])->save();

    $update = access_livewire_update($page->getContent());

    $update->assertRedirect(route('login'));
    expect($update->getContent())->not->toContain('Student area probe');
    $this->assertGuest();
});

it('signs out a suspended student on the next livewire update', function () {
    $student = access_student();
    $this->actingAs($student->user);

    $page = $this->get('/student/livewire-probe')->assertOk();

    $student->user->forceFill(['status' => UserStatus::Suspended])->save();

    $update = access_livewire_update($page->getContent());

    $update->assertRedirect(route('login'));
    expect($update->getContent())->not->toContain('Student area probe');
    $this->assertGuest();
});

it('rejects a staff panel livewire update after the password flag is set', function () {
    $staff = access_staff();
    $this->actingAs($staff);

    $page = $this->get('/staff');
    $page->assertOk();
    expect($page->getContent())->toContain('wire:snapshot');

    $staff->forceFill([
        'must_change_password' => true,
        'temp_password_expires_at' => now()->addDay(),
    ])->save();

    $update = access_livewire_update($page->getContent());

    $update->assertRedirect(route('password.edit'));
    expect($update->getContent())->not->toContain('Account');
    $this->assertAuthenticated();
});

it('signs out a deactivated staff user on the next livewire update', function () {
    $staff = access_staff();
    $this->actingAs($staff);

    $page = $this->get('/staff')->assertOk();

    $staff->forceFill(['status' => UserStatus::Deactivated])->save();

    $update = access_livewire_update($page->getContent());

    $update->assertRedirect(route('login'));
    expect($update->getContent())->not->toContain('Account');
    $this->assertGuest();
});

it('signs out a suspended staff user on the next livewire update', function () {
    $staff = access_staff();
    $this->actingAs($staff);

    $page = $this->get('/staff')->assertOk();

    $staff->forceFill(['status' => UserStatus::Suspended])->save();

    $update = access_livewire_update($page->getContent());

    $update->assertRedirect(route('login'));
    expect($update->getContent())->not->toContain('Account');
    $this->assertGuest();
});

it('signs out a staff user whose last role assignment is removed mid-session', function () {
    $staff = access_staff(user_attributes: [
        'email' => 'registrar@example.com',
    ]);

    $this->post('/login', [
        'identifier' => 'registrar@example.com',
        'password' => access_password(),
    ])->assertRedirect('/staff');

    $this->get('/staff')->assertOk();

    $staff->roleAssignments()->delete();
    auth()->forgetUser();

    $this->get('/staff')->assertRedirect(route('login'));
    $this->assertGuest();
    $this->get('/login')->assertOk();
});

it('redirects each signed-in user to their own area', function () {
    $student = access_student();
    $staff = access_staff();
    $both = access_staff(user_attributes: [
        'email' => 'both@example.com',
    ]);
    Student::factory()->create([
        'user_id' => $both->id,
        'matric_no' => 'CSC/2026/0099',
    ]);

    $student_response = $this->actingAs($student->user)->get('/staff');
    $student_response->assertRedirect('/student');
    $student_response->assertDontSee('Account');
    expect($student_response->getContent())->not->toContain('wire:snapshot');

    $staff_response = $this->actingAs($staff)->get('/student');
    $staff_response->assertRedirect('/staff');
    $staff_response->assertDontSee('Student portal');

    $this->actingAs($both)->get('/staff')->assertOk();
    $this->actingAs($both)->get('/student')->assertOk()->assertSee('Student portal');
});

it('signs out a deactivated user on the next page request', function () {
    $student = access_student();
    $this->actingAs($student->user);
    $this->get('/student')->assertOk();

    $student->user->forceFill(['status' => UserStatus::Deactivated])->save();

    $this->get('/student')->assertRedirect(route('login'));
    $this->assertGuest();
    $this->get('/login')->assertOk();
});

it('leaves webhook routes outside the auth groups', function () {
    $this->get('/payments/notify/ping')
        ->assertOk()
        ->assertSee('webhook routes ok');
});
