<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

/**
 * Password that can sign in. It stays in this file.
 */
function log_safety_password(): string
{
    return 'Log-safety-1';
}

/**
 * Password that must be refused.
 */
function log_safety_wrong_password(): string
{
    return 'Wrong-pass-45';
}

/**
 * Replacement password for the change-password case.
 */
function log_safety_new_password(): string
{
    return 'River-lamp-45';
}

/**
 * Email that must not be written to the log.
 */
function log_safety_email(): string
{
    return 'log.safety@example.com';
}

/**
 * Matric number that must not be written to the log.
 */
function log_safety_matric(): string
{
    return 'CSC/2045/8841';
}

/**
 * A student with the log-safety email, matric number, and password.
 */
function log_safety_student(): Student
{
    $user = User::factory()->create([
        'email' => log_safety_email(),
        'password' => log_safety_password(),
        'status' => UserStatus::Active,
    ]);

    return Student::factory()->create([
        'user_id' => $user->id,
        'matric_no' => log_safety_matric(),
    ]);
}

/**
 * Address-plus-identifier limit key. The identifier is an HMAC, not plaintext.
 */
function log_safety_pair_key(string $identifier, string $address): string
{
    $trimmed = trim($identifier);
    $collapsed = preg_replace('/\s+/u', '', $trimmed);
    $normalised = str_contains($trimmed, '@')
        ? mb_strtolower($trimmed)
        : mb_strtoupper(is_string($collapsed) ? $collapsed : $trimmed);
    $hmac = hash_hmac('sha256', $normalised, (string) config('app.key'));

    return 'login-pair:'.$hmac.':'.$address;
}

/**
 * True when the secret appears in the captured log output.
 */
function log_safety_leaked(string $output, string $secret): bool
{
    return $secret !== '' && str_contains($output, $secret);
}

/**
 * Run an action and return the log records it emitted.
 *
 * The historical laravel.log file is not read. A failure reports a boolean,
 * so the secret is not printed.
 */
function log_safety_capture(callable $action): string
{
    $path = storage_path('logs/log-safety-'.bin2hex(random_bytes(8)).'.log');
    $records = [];

    config([
        'logging.default' => 'single',
        'logging.channels.single.path' => $path,
    ]);
    Log::forgetChannel('single');
    Log::forgetChannel('stack');

    Log::listen(function (MessageLogged $event) use (&$records): void {
        $encoded = json_encode($event->context);
        $records[] = $event->message.' '.(is_string($encoded) ? $encoded : '');
    });

    $output = '';

    try {
        $action();
    } finally {
        $file = is_file($path) ? (string) file_get_contents($path) : '';

        if (is_file($path)) {
            unlink($path);
        }

        $output = implode("\n", $records)."\n".$file;
    }

    return $output;
}

beforeEach(function (): void {
    $this->withoutVite();
});

it('keeps a successful login out of the log', function () {
    log_safety_student();

    $output = log_safety_capture(function (): void {
        $this->post('/login', [
            'identifier' => log_safety_matric(),
            'password' => log_safety_password(),
        ])->assertRedirect('/student');
    });

    $leaked_password = log_safety_leaked($output, log_safety_password());

    expect($leaked_password)->toBeFalse();
});

it('keeps a failed login out of the log', function () {
    log_safety_student();

    $output = log_safety_capture(function (): void {
        $this->post('/login', [
            'identifier' => log_safety_email(),
            'password' => log_safety_wrong_password(),
        ])->assertRedirect('/login');

        $this->post('/login', [
            'identifier' => log_safety_matric(),
            'password' => log_safety_wrong_password(),
        ])->assertRedirect('/login');
    });

    $leaked_password = log_safety_leaked($output, log_safety_password());
    $leaked_wrong_password = log_safety_leaked($output, log_safety_wrong_password());
    $leaked_email = log_safety_leaked($output, log_safety_email());
    $leaked_matric = log_safety_leaked($output, log_safety_matric());

    expect($leaked_password)->toBeFalse()
        ->and($leaked_wrong_password)->toBeFalse()
        ->and($leaked_email)->toBeFalse()
        ->and($leaked_matric)->toBeFalse();
});

it('keeps a lockout out of the log', function () {
    log_safety_student();

    $output = log_safety_capture(function (): void {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->withServerVariables([
                'REMOTE_ADDR' => '203.0.113.45',
            ])->post('/login', [
                'identifier' => log_safety_email(),
                'password' => log_safety_wrong_password(),
            ])->assertRedirect('/login');
        }

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->withServerVariables([
                'REMOTE_ADDR' => '203.0.113.46',
            ])->post('/login', [
                'identifier' => log_safety_matric(),
                'password' => log_safety_wrong_password(),
            ])->assertRedirect('/login');
        }
    });

    $email_locked = RateLimiter::tooManyAttempts(log_safety_pair_key(log_safety_email(), '203.0.113.45'), 5);
    $matric_locked = RateLimiter::tooManyAttempts(log_safety_pair_key(log_safety_matric(), '203.0.113.46'), 5);
    $leaked_password = log_safety_leaked($output, log_safety_password());
    $leaked_wrong_password = log_safety_leaked($output, log_safety_wrong_password());
    $leaked_email = log_safety_leaked($output, log_safety_email());
    $leaked_matric = log_safety_leaked($output, log_safety_matric());

    expect($email_locked)->toBeTrue()
        ->and($matric_locked)->toBeTrue()
        ->and($leaked_password)->toBeFalse()
        ->and($leaked_wrong_password)->toBeFalse()
        ->and($leaked_email)->toBeFalse()
        ->and($leaked_matric)->toBeFalse();
});

it('keeps a password change out of the log', function () {
    $student = log_safety_student();
    $student->user->forceFill([
        'must_change_password' => true,
        'temp_password_expires_at' => now()->addDay(),
    ])->save();

    $output = log_safety_capture(function () use ($student): void {
        $this->actingAs($student->user)->post('/change-password', [
            'current_password' => log_safety_password(),
            'password' => log_safety_new_password(),
            'password_confirmation' => log_safety_new_password(),
        ])->assertRedirect('/student');
    });

    $token = (string) $student->user->refresh()->remember_token;
    $leaked_current = log_safety_leaked($output, log_safety_password());
    $leaked_new = log_safety_leaked($output, log_safety_new_password());
    $leaked_token = log_safety_leaked($output, $token);

    expect(strlen($token))->toBe(60)
        ->and($leaked_current)->toBeFalse()
        ->and($leaked_new)->toBeFalse()
        ->and($leaked_token)->toBeFalse();
});
