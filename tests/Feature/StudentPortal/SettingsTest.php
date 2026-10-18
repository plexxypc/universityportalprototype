<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * Current password for the settings page. It stays in this file.
 */
function settings_current_password(): string
{
    return 'Portal-pass-1';
}

/**
 * A replacement that passes the password rules.
 */
function settings_new_password(): string
{
    return 'River-lamp-29';
}

/**
 * A student who can open account settings.
 */
function settings_student(): Student
{
    $user = User::factory()->create([
        'email' => 'chioma.settings@example.test',
        'password' => settings_current_password(),
        'status' => UserStatus::Active,
        'must_change_password' => false,
    ]);

    return Student::factory()->create([
        'user_id' => $user->id,
        'first_name' => 'Chioma',
        'last_name' => 'Adeyemi',
        'matric_no' => 'CSC/2026/0501',
    ]);
}

test('account settings shows identity as text and posts to the existing password action', function () {
    $student = settings_student();

    $this->actingAs($student->user)
        ->get(route('student.settings'))
        ->assertOk()
        ->assertSee('Chioma Adeyemi')
        ->assertSee('chioma.settings@example.test')
        ->assertSee('CSC/2026/0501')
        ->assertSee(route('password.update'), false)
        ->assertDontSee('name="email"', false)
        ->assertDontSee('name="matric_no"', false);
});

test('the wrong current password is refused', function () {
    $student = settings_student();
    $hash = $student->user->password;

    $this->actingAs($student->user)
        ->from(route('student.settings'))
        ->post(route('password.update'), [
            'current_password' => 'Wrong-pass-1',
            'password' => settings_new_password(),
            'password_confirmation' => settings_new_password(),
        ])
        ->assertRedirect(route('student.settings'))
        ->assertSessionHasErrors('current_password');

    expect(Hash::check(settings_current_password(), (string) $student->user->fresh()->password))->toBeTrue()
        ->and($student->user->fresh()->password)->toBe($hash);
});

test('a password change from settings signs out the other browser and does not store the password', function () {
    config(['session.driver' => 'database']);
    $student = settings_student();
    $user = $student->user;
    $current = settings_current_password();
    $replacement = settings_new_password();

    $this->actingAs($user)->get(route('student.settings'))->assertOk();

    $other_id = 'other-session-'.$user->getAuthIdentifier();
    DB::table('sessions')->insert([
        'id' => $other_id,
        'user_id' => $user->getAuthIdentifier(),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'other-browser',
        'payload' => base64_encode('other'),
        'last_activity' => time(),
    ]);

    $this->actingAs($user)
        ->post(route('password.update'), [
            'current_password' => $current,
            'password' => $replacement,
            'password_confirmation' => $replacement,
        ])
        ->assertRedirect(route('student.home'));

    $user->refresh();
    $audit = AuditLog::query()->where('actor_id', $user->id)->latest('id')->first();
    $audit_text = json_encode($audit?->toArray());
    $session_text = (string) DB::table('sessions')->where('user_id', $user->id)->value('payload');

    expect(Hash::check($replacement, (string) $user->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', $other_id)->exists())->toBeFalse()
        ->and(is_string($audit_text) && str_contains($audit_text, $current))->toBeFalse()
        ->and(is_string($audit_text) && str_contains($audit_text, $replacement))->toBeFalse()
        ->and(str_contains($session_text, $current))->toBeFalse()
        ->and(str_contains($session_text, $replacement))->toBeFalse();
});
