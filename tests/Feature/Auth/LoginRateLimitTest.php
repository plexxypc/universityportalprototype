<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Models\Student;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Fixture password for rate-limit attempts.
 */
function rate_limit_password(): string
{
    return 'Portal-pass-1';
}

/**
 * A student who can sign in with the fixture password.
 */
function rate_limit_student(string $email, string $matric_no): Student
{
    $user = User::factory()->create([
        'email' => $email,
        'password' => rate_limit_password(),
        'status' => UserStatus::Active,
    ]);

    return Student::factory()->create([
        'user_id' => $user->id,
        'matric_no' => $matric_no,
    ]);
}

/**
 * Post a login from one socket address.
 */
function rate_limit_post(string $identifier, string $password, string $address, ?string $forwarded_for = null): TestResponse
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
 * Headers that may differ between two otherwise identical redirects.
 *
 * @return array<string, list<string|null>>
 */
function rate_limit_comparable_headers(TestResponse $response): array
{
    $headers = [];

    foreach ($response->headers->all() as $name => $values) {
        if (in_array(strtolower((string) $name), ['set-cookie', 'date'], true)) {
            continue;
        }

        $headers[strtolower((string) $name)] = $values;
    }

    ksort($headers);

    return $headers;
}

/**
 * HMAC key the limiter stores for this normalised identifier.
 */
function rate_limit_identifier_key(string $normalised): string
{
    $hmac = hash_hmac('sha256', $normalised, (string) config('app.key'));

    return 'login-identifier:'.$hmac;
}

beforeEach(function (): void {
    $this->withoutVite();
});

it('locks an identifier and address together after five failures', function () {
    rate_limit_student('ada@example.com', 'CSC/2026/0011');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        rate_limit_post('CSC/2026/0011', 'wrong-password-1', '203.0.113.10', '198.51.100.'.$attempt)
            ->assertRedirect('/login');
    }

    rate_limit_post('CSC/2026/0011', rate_limit_password(), '203.0.113.10', '198.51.100.99')
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['identifier' => AuthService::FAILURE_MESSAGE]);

    $this->assertGuest();
});

it('locks an identifier from any address after ten failures', function () {
    rate_limit_student('ada@example.com', 'CSC/2026/0012');

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        rate_limit_post('ada@example.com', 'wrong-password-1', '203.0.113.'.$attempt)
            ->assertRedirect('/login');
    }

    rate_limit_post('ada@example.com', rate_limit_password(), '203.0.113.50', '198.51.100.8')
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['identifier' => AuthService::FAILURE_MESSAGE]);

    $this->assertGuest();
});

it('does not let a forged X-Forwarded-For open a new login bucket', function () {
    rate_limit_student('ada@example.com', 'CSC/2026/0013');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        rate_limit_post('CSC/2026/0013', 'wrong-password-1', '203.0.113.20', '203.0.113.'.(30 + $attempt))
            ->assertRedirect('/login');
    }

    rate_limit_post('CSC/2026/0013', rate_limit_password(), '203.0.113.20', '198.51.100.77')
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['identifier' => AuthService::FAILURE_MESSAGE]);

    $this->assertGuest();
});

it('clears both login counters after a successful sign-in', function () {
    rate_limit_student('ada@example.com', 'CSC/2026/0014');

    for ($attempt = 0; $attempt < 4; $attempt++) {
        rate_limit_post('CSC/2026/0014', 'wrong-password-1', '203.0.113.40')
            ->assertRedirect('/login');
    }

    rate_limit_post('CSC/2026/0014', rate_limit_password(), '203.0.113.40')
        ->assertRedirect('/student');

    auth()->logout();
    $this->actingAsGuest();
    session()->invalidate();
    session()->save();

    for ($attempt = 0; $attempt < 4; $attempt++) {
        rate_limit_post('CSC/2026/0014', 'wrong-password-1', '203.0.113.40')
            ->assertRedirect('/login');
    }

    rate_limit_post('CSC/2026/0014', rate_limit_password(), '203.0.113.40')
        ->assertRedirect('/student');

    $this->assertAuthenticated();
});

it('matches a locked-out response to a wrong-password response', function () {
    rate_limit_student('ada@example.com', 'CSC/2026/0015');

    $wrong = rate_limit_post('ada@example.com', 'wrong-password-1', '203.0.113.60');
    $wrong->assertRedirect('/login')
        ->assertSessionHasErrors(['identifier' => AuthService::FAILURE_MESSAGE]);

    $wrong_status = $wrong->getStatusCode();
    $wrong_body = $wrong->getContent();
    $wrong_headers = rate_limit_comparable_headers($wrong);

    for ($attempt = 0; $attempt < 4; $attempt++) {
        rate_limit_post('ada@example.com', 'wrong-password-1', '203.0.113.60');
    }

    $locked = rate_limit_post('ada@example.com', rate_limit_password(), '203.0.113.60', '198.51.100.1');
    $locked->assertSessionHasErrors(['identifier' => AuthService::FAILURE_MESSAGE]);

    expect($locked->getStatusCode())->toBe($wrong_status)
        ->and($locked->getStatusCode())->not->toBe(429)
        ->and($locked->headers->has('Retry-After'))->toBeFalse()
        ->and($locked->getContent())->toBe($wrong_body)
        ->and(rate_limit_comparable_headers($locked))->toBe($wrong_headers);
});

it('checks the password once when the identifier is locked', function () {
    rate_limit_student('ada@example.com', 'CSC/2026/0016');
    rate_limit_post('nobody-warmup@example.com', rate_limit_password(), '203.0.113.69');
    $hasher = app('hash')->driver();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        rate_limit_post('ada@example.com', 'wrong-password-1', '203.0.113.70');
    }

    Hash::partialMock()
        ->shouldReceive('check')
        ->once()
        ->andReturnUsing(function (string $plain, string $hashed) use ($hasher): bool {
            return $hasher->check($plain, $hashed);
        });

    rate_limit_post('ada@example.com', rate_limit_password(), '203.0.113.70')
        ->assertRedirect('/login');
});

it('checks a locked attempt against the same dummy hash as an unknown user', function () {
    rate_limit_student('ada@example.com', 'CSC/2026/0017');
    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
    rate_limit_post('nobody-warmup@example.com', rate_limit_password(), '203.0.113.79');
    $identifier_key = rate_limit_identifier_key('ada@example.com');

    for ($attempt = 0; $attempt < 10; $attempt++) {
        RateLimiter::hit($identifier_key, 900);
    }

    $hasher = app('hash')->driver();
    $seen = [];

    Hash::partialMock()
        ->shouldReceive('check')
        ->twice()
        ->andReturnUsing(function (string $plain, string $hashed) use ($hasher, &$seen): bool {
            $seen[] = $hashed;

            return $hasher->check($plain, $hashed);
        });

    rate_limit_post('missing@example.com', rate_limit_password(), '203.0.113.80');
    rate_limit_post('ada@example.com', rate_limit_password(), '203.0.113.81', '198.51.100.5')
        ->assertRedirect('/login');

    expect($seen)->toHaveCount(2)
        ->and($seen[1])->toBe($seen[0])
        ->and($seen[1])->not->toBe($user->getAuthPassword());

    $this->assertGuest();
});

it('stores an hmac of the identifier and not the identifier itself', function () {
    rate_limit_student('ada@example.com', 'CSC/2026/0018');

    rate_limit_post('ada@example.com', 'wrong-password-1', '203.0.113.90');
    rate_limit_post('csc / 2026 / 0018', 'wrong-password-1', '203.0.113.91');

    expect(RateLimiter::attempts(rate_limit_identifier_key('ada@example.com')))->toBe(1)
        ->and(RateLimiter::attempts(rate_limit_identifier_key('CSC/2026/0018')))->toBe(1)
        ->and(RateLimiter::attempts('login-identifier:ada@example.com'))->toBe(0)
        ->and(RateLimiter::attempts('login-identifier:CSC/2026/0018'))->toBe(0);
});
