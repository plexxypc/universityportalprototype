<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\PasswordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Current password for these tests. It stays in this file.
 */
function change_current_password(): string
{
    return 'Portal-pass-1';
}

/**
 * A replacement that passes the password rules.
 */
function change_new_password(): string
{
    return 'River-lamp-29';
}

/**
 * A student who can open the change-password page.
 */
function change_student(array $user_attributes = []): Student
{
    $user = User::factory()->create(array_merge([
        'email' => 'ada@example.com',
        'password' => change_current_password(),
        'status' => UserStatus::Active,
        'must_change_password' => true,
        'temp_password_expires_at' => now()->addDays(7),
    ], $user_attributes));

    return Student::factory()->create([
        'user_id' => $user->id,
        'matric_no' => 'CSC/2026/0001',
    ]);
}

/**
 * A staff user who can open the change-password page.
 */
function change_staff(array $user_attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'email' => 'registrar@example.com',
        'password' => change_current_password(),
        'status' => UserStatus::Active,
        'must_change_password' => true,
        'temp_password_expires_at' => now()->addDays(7),
    ], $user_attributes));

    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => Role::Registrar,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    return $user;
}

/**
 * Post the change-password form.
 *
 * @param  array<string, mixed>  $overrides
 */
function post_change_password(array $overrides = []): TestResponse
{
    return test()->post(route('password.update'), array_merge([
        'current_password' => change_current_password(),
        'password' => change_new_password(),
        'password_confirmation' => change_new_password(),
    ], $overrides));
}

/**
 * Change a password while sessions are stored in the testing database.
 */
function assert_password_change_keeps_this_browser(User $user, string $home): void
{
    config(['session.driver' => 'database']);

    $token_before = $user->remember_token;

    test()->actingAs($user)->get(route('password.edit'))->assertOk();
    $session_before = session()->getId();

    $other_id = 'other-session-'.$user->getAuthIdentifier();
    DB::table('sessions')->insert([
        'id' => $other_id,
        'user_id' => $user->getAuthIdentifier(),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'other-browser',
        'payload' => base64_encode('other'),
        'last_activity' => time(),
    ]);

    post_change_password()->assertRedirect($home);

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and($user->temp_password_expires_at)->toBeNull()
        ->and($user->remember_token)->not->toBe($token_before)
        ->and(Hash::check(change_new_password(), (string) $user->password))->toBeTrue()
        ->and(session()->getId())->not->toBe($session_before)
        ->and(DB::table('sessions')->where('id', $other_id)->exists())->toBeFalse()
        ->and(session('password_hash_web'))->toBe(
            auth()->guard()->hashPasswordForCookie((string) $user->getAuthPassword())
        );

    test()->get($home)
        ->assertOk()
        ->assertSee('Your password has been changed.');
}

it('changes a student password and keeps only this browser signed in', function () {
    $student = change_student();

    assert_password_change_keeps_this_browser($student->user, '/student');

    test()->get('/student')->assertSee('Student portal');
});

it('changes a staff password and keeps only this browser signed in', function () {
    $staff = change_staff();

    assert_password_change_keeps_this_browser($staff, '/staff');

    test()->get('/staff')->assertSee('Account');
});

it('rejects a weak password', function () {
    $student = change_student();
    $this->actingAs($student->user);

    foreach (['short1', 'abcdefghij', '1234567890', str_repeat('a', 72).'1'] as $password) {
        post_change_password([
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertSessionHasErrors(['password' => 'Choose a different password.']);
    }

    expect(Hash::check(change_current_password(), (string) $student->user->fresh()->password))->toBeTrue();
});

it('rejects a password equal to the current password', function () {
    $student = change_student([
        'must_change_password' => false,
        'temp_password_expires_at' => null,
    ]);
    $this->actingAs($student->user);

    post_change_password([
        'password' => change_current_password(),
        'password_confirmation' => change_current_password(),
    ])->assertSessionHasErrors(['password' => 'Choose a different password.']);

    expect($student->user->fresh()->must_change_password)->toBeFalse()
        ->and(Hash::check(change_current_password(), (string) $student->user->fresh()->password))->toBeTrue();
});

it('rejects a password equal to the temporary password', function () {
    $student = change_student();
    $this->actingAs($student->user);

    post_change_password([
        'password' => change_current_password(),
        'password_confirmation' => change_current_password(),
    ])->assertSessionHasErrors(['password' => 'Choose a different password.']);

    $fresh = $student->user->fresh();
    expect($fresh->must_change_password)->toBeTrue()
        ->and($fresh->temp_password_expires_at)->not->toBeNull()
        ->and(Hash::check(change_current_password(), (string) $fresh->password))->toBeTrue();
});

it('rejects a common password after lowercasing it', function () {
    $student = change_student();
    $this->actingAs($student->user);

    post_change_password([
        'password' => 'Password12',
        'password_confirmation' => 'Password12',
    ])->assertSessionHasErrors(['password' => 'Choose a different password.']);
});

it('rejects a password that contains the email or matric number', function () {
    $student = change_student();
    $this->actingAs($student->user);

    foreach (['xxada@example.com1', 'aaCSC/2026/00011'] as $password) {
        post_change_password([
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertSessionHasErrors(['password' => 'Choose a different password.']);
    }
});

it('uses the same field error when the current password is wrong', function () {
    $student = change_student();
    $this->actingAs($student->user);

    post_change_password([
        'current_password' => 'wrong-current-1',
    ])->assertSessionHasErrors([
        'current_password' => PasswordService::CURRENT_PASSWORD_ERROR,
    ]);

    expect(session('_old_input.current_password'))->toBeNull()
        ->and(session('_old_input.password'))->toBeNull();
});

it('stops accepting the change-password form after repeated attempts', function () {
    $student = change_student();
    $this->actingAs($student->user);

    for ($attempt = 0; $attempt < PasswordService::MAX_ATTEMPTS; $attempt++) {
        post_change_password([
            'current_password' => 'wrong-current-1',
        ])->assertSessionHasErrors([
            'current_password' => PasswordService::CURRENT_PASSWORD_ERROR,
        ]);
    }

    post_change_password()->assertSessionHasErrors([
        'current_password' => PasswordService::CURRENT_PASSWORD_ERROR,
    ]);

    expect(Hash::check(change_current_password(), (string) $student->user->fresh()->password))->toBeTrue()
        ->and($student->user->fresh()->must_change_password)->toBeTrue();
});

it('does not change another user when a student posts their id', function () {
    $student = change_student();
    $other = change_staff([
        'email' => 'other@example.com',
    ]);
    $this->actingAs($student->user);

    post_change_password([
        'user_id' => $other->id,
    ])->assertRedirect('/student');

    expect(Hash::check(change_new_password(), (string) $student->user->fresh()->password))->toBeTrue()
        ->and(Hash::check(change_current_password(), (string) $other->fresh()->password))->toBeTrue();
});
