<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Support\SafeReturnPath;
use App\Support\SessionReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

uses(RefreshDatabase::class);

/**
 * Fixture password for these session tests. It stays in this file.
 */
function logout_password(): string
{
    return 'Portal-pass-1';
}

/**
 * A student who can open /student.
 */
function logout_student(): Student
{
    $user = User::factory()->create([
        'password' => logout_password(),
        'status' => UserStatus::Active,
    ]);

    return Student::factory()->create([
        'user_id' => $user->id,
        'matric_no' => 'CSC/2026/0043',
    ]);
}

/**
 * A staff user who can open /staff.
 */
function logout_staff(): User
{
    $user = User::factory()->create([
        'email' => 'session-registrar@example.test',
        'password' => logout_password(),
        'status' => UserStatus::Active,
    ]);

    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => Role::Registrar,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    return $user;
}

beforeEach(function (): void {
    $this->withoutVite();
});

it('invalidates the session so the old session id no longer works', function () {
    config(['session.driver' => 'database']);

    $student = logout_student();
    $cookie = (string) config('session.cookie');

    $this->actingAs($student->user);
    $this->get('/student')->assertOk();

    $old_id = session()->getId();

    expect(DB::table('sessions')->where('id', $old_id)->exists())->toBeTrue();

    $this->withCookie($cookie, $old_id)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->app['auth']->forgetGuards();

    expect(DB::table('sessions')->where('id', $old_id)->exists())->toBeFalse();

    $this->withCookie($cookie, $old_id)
        ->get('/student')
        ->assertRedirect(route('login', ['expired' => '1']));

    $this->assertGuest();
});

it('logs out of the staff panel through the same authenticator', function () {
    $staff = logout_staff();

    $this->actingAs($staff);
    $this->get('/staff')->assertOk();

    $cookie = (string) config('session.cookie');
    $session_id = session()->getId();

    $this->withCookie($cookie, $session_id)
        ->post(route('filament.staff.auth.logout'))
        ->assertRedirect(route('login'));

    $this->app['auth']->forgetGuards();
    $this->assertGuest();

    $this->withCookie($cookie, $session_id)
        ->get('/staff')
        ->assertRedirect(route('login', ['expired' => '1']));
});

it('rejects an external url as the post-login return path', function () {
    $student = logout_student();

    $this->get('/login')->assertOk();
    session([SessionReturn::INTENDED => 'https://evil.example/phish']);

    $this->post('/login', [
        'identifier' => $student->matric_no,
        'password' => logout_password(),
    ])->assertRedirect('/student');

    $this->assertAuthenticated();
    expect(session(SessionReturn::INTENDED))->toBeNull();
});

it('returns to a same-site page after login', function () {
    $student = logout_student();

    $this->get('/login')->assertOk();
    session([SessionReturn::INTENDED => '/student/ping']);

    $this->post('/login', [
        'identifier' => $student->matric_no,
        'password' => logout_password(),
    ])->assertRedirect('/student/ping');
});

it('stores a return path only for a plain get page', function () {
    $this->get('/student')
        ->assertRedirect(route('login'));

    expect(session(SessionReturn::INTENDED))->toBe('/student');

    $this->post('/logout');

    expect(session(SessionReturn::INTENDED))->toBe('/student');

    $this->withHeader('X-Livewire', 'true')
        ->postJson(EndpointResolver::updatePath(), [
            'components' => [],
        ]);

    expect(session(SessionReturn::INTENDED))->toBe('/student');

    $page = Request::create('/student', 'GET');
    $post = Request::create('/student', 'POST');
    $livewire = Request::create(EndpointResolver::updatePath(), 'POST');
    $legacy_livewire = Request::create('/livewire/update', 'POST');
    $asset = Request::create('/build/app.css', 'GET');
    $probe = Request::create('/favicon.ico', 'GET');
    $health = Request::create('/health', 'GET');

    expect(SafeReturnPath::shouldRemember($page))->toBeTrue()
        ->and(SafeReturnPath::shouldRemember($post))->toBeFalse()
        ->and(SafeReturnPath::shouldRemember($livewire))->toBeFalse()
        ->and(SafeReturnPath::fromRequest($livewire))->toBeNull()
        ->and(SafeReturnPath::shouldRemember($legacy_livewire))->toBeFalse()
        ->and(SafeReturnPath::fromRequest($legacy_livewire))->toBeNull()
        ->and(SafeReturnPath::shouldRemember($asset))->toBeFalse()
        ->and(SafeReturnPath::shouldRemember($probe))->toBeFalse()
        ->and(SafeReturnPath::shouldRemember($health))->toBeFalse()
        ->and(SafeReturnPath::accept('https://evil.example/student'))->toBeNull()
        ->and(SafeReturnPath::accept('//evil.example/student'))->toBeNull()
        ->and(SafeReturnPath::accept('/\\evil.example'))->toBeNull();
});

it('redirects a livewire request on an expired session to login', function () {
    $student = logout_student();
    $this->actingAs($student->user);

    $html = $this->get('/student/livewire-probe')->assertOk()->getContent();
    $matched = preg_match('/wire:snapshot="([^"]*)"/', $html, $matches);

    expect($matched)->toBe(1);

    $token = csrf_token();
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
    $cookie = (string) config('session.cookie');
    $session_id = session()->getId();

    session()->invalidate();
    $this->app['auth']->forgetGuards();

    $response = $this
        ->withCredentials()
        ->withCookie($cookie, $session_id)
        ->withHeader('X-Livewire', 'true')
        ->withHeader('X-CSRF-TOKEN', $token)
        ->postJson(EndpointResolver::updatePath(), [
            'components' => [
                [
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [],
                ],
            ],
        ]);

    $response->assertRedirect(route('login', ['expired' => '1']));
    expect($response->status())->not->toBe(419)
        ->and($response->getContent())->not->toContain('This page has expired')
        ->and(session(SessionReturn::INTENDED))->toBeNull();
});

it('shows a friendly message when the session has ended', function () {
    $this->get('/login')->assertOk()->assertDontSee('Your session has ended.');

    $this->withCookie((string) config('session.cookie'), session()->getId())
        ->get('/student')
        ->assertRedirect(route('login', ['expired' => '1']));

    $this->get('/login?expired=1')
        ->assertOk()
        ->assertSee('Your session has ended. Sign in to continue.');
});
