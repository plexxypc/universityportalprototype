<?php

declare(strict_types=1);

use App\Enums\EmailStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\EmailOutbox;
use App\Models\Student;
use App\Models\User;
use App\Services\AuthService;
use App\Services\PasswordResetService;
use App\Support\Mail\MailError;
use App\Support\Mail\OutboundMessage;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * A password that passes the portal rules.
 */
function password_reset_new_password(): string
{
    return 'Portal-pass-2';
}

/**
 * Whether the needle appears. A failed expectation does not print it.
 */
function password_reset_contains(string $haystack, string $needle): bool
{
    return $needle !== '' && str_contains($haystack, $needle);
}

/**
 * An active student who can request a reset.
 */
function password_reset_student(string $email, string $matric_no, array $user_attributes = []): Student
{
    $user = User::factory()->create(array_merge([
        'name' => 'Ada Reset',
        'email' => $email,
        'password' => 'Portal-pass-1',
        'status' => UserStatus::Active,
        'must_change_password' => false,
        'temp_password_expires_at' => null,
    ], $user_attributes));

    return Student::factory()->create([
        'user_id' => $user->id,
        'matric_no' => $matric_no,
    ]);
}

/**
 * Point the rate limiter at the database cache for this test.
 */
function password_reset_use_database_cache(): void
{
    config(['cache.default' => 'database']);
    app()->forgetInstance('cache');
    app()->forgetInstance('cache.store');
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
}

/**
 * Post the forgot-password form and follow the redirect.
 */
function password_reset_request(string $identifier, string $address = '203.0.113.10', ?string $forwarded_for = null): TestResponse
{
    $pending = test()->withServerVariables([
        'REMOTE_ADDR' => $address,
    ]);

    if ($forwarded_for !== null) {
        $pending = $pending->withHeader('X-Forwarded-For', $forwarded_for);
    }

    return $pending->followingRedirects()->post('/forgot-password', [
        'identifier' => $identifier,
    ]);
}

/**
 * HTML with session tokens and injected Livewire assets removed, so two responses can be compared.
 */
function password_reset_visible_html(string $html): string
{
    $stripped = preg_replace('/<!-- Livewire Styles -->.*?<\/style>/s', '', $html) ?? $html;
    $stripped = preg_replace('/<script\b[^>]*\blivewire\b[^>]*>\s*<\/script>/i', '', $stripped) ?? $stripped;
    $stripped = preg_replace('/\bvalue="[^"]*"/', 'value=""', $stripped) ?? $stripped;
    $stripped = preg_replace('/\bcontent="[^"]*"/', 'content=""', $stripped) ?? $stripped;
    $stripped = preg_replace('/\s+/', ' ', $stripped) ?? $stripped;

    return trim($stripped);
}

/**
 * Post a login from one socket address.
 */
function password_reset_login(string $identifier, string $password, string $address): TestResponse
{
    return test()->withServerVariables([
        'REMOTE_ADDR' => $address,
    ])->post('/login', [
        'identifier' => $identifier,
        'password' => $password,
    ]);
}

/**
 * Plain token from the latest array-transport message, or an empty string.
 */
function password_reset_token_from_mail(): string
{
    $transport = Mail::mailer()->getSymfonyTransport();

    if (! $transport instanceof ArrayTransport) {
        return '';
    }

    $message = $transport->messages()->last();

    if ($message === null) {
        return '';
    }

    $original = $message->getOriginalMessage();
    $html = method_exists($original, 'getHtmlBody') ? (string) $original->getHtmlBody() : '';
    $matched = preg_match('#/reset-password/([A-Za-z0-9]+)#', $html, $found) === 1;

    return $matched ? (string) ($found[1] ?? '') : '';
}

/**
 * Whether the reset URL in the latest message contains the email address.
 *
 * @return array{leaked: bool, shaped: bool}
 */
function password_reset_url_flags(string $email): array
{
    $transport = Mail::mailer()->getSymfonyTransport();
    $leaked = false;
    $shaped = false;

    if (! $transport instanceof ArrayTransport) {
        return ['leaked' => true, 'shaped' => false];
    }

    $message = $transport->messages()->last();

    if ($message === null) {
        return ['leaked' => true, 'shaped' => false];
    }

    $original = $message->getOriginalMessage();
    $html = method_exists($original, 'getHtmlBody') ? (string) $original->getHtmlBody() : '';

    if (preg_match_all('#https?://[^\s"\']+#', $html, $urls) < 1) {
        return ['leaked' => false, 'shaped' => false];
    }

    foreach ($urls[0] as $url) {
        if (! is_string($url) || ! str_contains($url, '/reset-password/')) {
            continue;
        }

        $shaped = preg_match('#/reset-password/[A-Za-z0-9]+$#', $url) === 1
            && ! str_contains($url, '@')
            && ! str_contains(strtolower($url), 'email=');
        $leaked = password_reset_contains(strtolower($url), strtolower($email));
    }

    return ['leaked' => $leaked, 'shaped' => $shaped];
}

/**
 * Whether the reset form sent the browser to the login page.
 *
 * The check is a boolean so a failure does not print the reset URL.
 */
function password_reset_landed_on_login(TestResponse $response): bool
{
    $location = (string) $response->headers->get('Location');

    return $response->isRedirect() && str_ends_with($location, '/login');
}

/**
 * Post the reset form.
 */
function password_reset_submit(
    string $token,
    string $identifier,
    string $password,
    string $address = '203.0.113.40',
    ?string $confirmation = null,
): TestResponse {
    return test()->withServerVariables([
        'REMOTE_ADDR' => $address,
    ])->from('/reset-password/'.$token)->post('/reset-password/'.$token, [
        'identifier' => $identifier,
        'password' => $password,
        'password_confirmation' => $confirmation ?? $password,
    ]);
}

beforeEach(function (): void {
    $this->withoutVite();

    if (app()->bound('livewire')) {
        app('livewire')->flushState();
    }
});

it('answers every forgot-password request with the same page', function () {
    $active = password_reset_student('ada.active@example.com', 'CSC/2026/0041');
    $deactivated = password_reset_student('ada.off@example.com', 'CSC/2026/0042', [
        'status' => UserStatus::Deactivated,
    ]);
    $suspended = password_reset_student('ada.held@example.com', 'CSC/2026/0043', [
        'status' => UserStatus::Suspended,
    ]);

    $active_page = password_reset_request($active->user->email);
    $unknown_page = password_reset_request('nobody@example.com');
    $deactivated_page = password_reset_request($deactivated->user->email);
    $matric_page = password_reset_request('csc/2026/0041');
    $suspended_page = password_reset_request($suspended->user->email);

    $active_html = password_reset_visible_html($active_page->getContent());

    expect($active_page->status())->toBe(200)
        ->and($unknown_page->status())->toBe($active_page->status())
        ->and($deactivated_page->status())->toBe($active_page->status())
        ->and($matric_page->status())->toBe($active_page->status())
        ->and($suspended_page->status())->toBe($active_page->status())
        ->and(password_reset_visible_html($unknown_page->getContent()))->toBe($active_html)
        ->and(password_reset_visible_html($deactivated_page->getContent()))->toBe($active_html)
        ->and(password_reset_visible_html($matric_page->getContent()))->toBe($active_html)
        ->and(password_reset_visible_html($suspended_page->getContent()))->toBe($active_html)
        ->and($active_html)->toContain(PasswordResetService::REQUEST_MESSAGE);

    $rows = EmailOutbox::query()->where('user_id', $active->user->id)->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (EmailOutbox $row): bool => $row->template === 'password_reset'))->toBeTrue()
        ->and(EmailOutbox::query()->where('user_id', $deactivated->user->id)->exists())->toBeFalse()
        ->and(EmailOutbox::query()->where('user_id', $suspended->user->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', $deactivated->user->email)->exists())->toBeFalse()
        ->and(DB::table('password_reset_tokens')->where('email', $suspended->user->email)->exists())->toBeFalse();
});

it('waits until terminate to look up the account', function () {
    $student = password_reset_student('ada.later@example.com', 'CSC/2026/0044');
    $request = Request::create('/forgot-password', 'POST', server: ['REMOTE_ADDR' => '203.0.113.20']);

    app(PasswordResetService::class)->requestLink($request, $student->user->email);

    expect(EmailOutbox::query()->where('user_id', $student->user->id)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', $student->user->email)->count())->toBe(0);

    app()->terminate();

    expect(EmailOutbox::query()->where('user_id', $student->user->id)->count())->toBe(1);
});

it('stores no token in the outbox after the email is sent', function () {
    $student = password_reset_student('ada.sent@example.com', 'CSC/2026/0045');

    password_reset_request($student->user->email, '203.0.113.21');

    $row = EmailOutbox::query()->where('user_id', $student->user->id)->first();
    $token = password_reset_token_from_mail();
    $flags = password_reset_url_flags($student->user->email);
    $raw_secrets = $row instanceof EmailOutbox ? (string) $row->getRawOriginal('secrets') : '';
    $body = $row instanceof EmailOutbox ? (string) $row->body_html.(string) $row->body_text : '';
    $queue = DB::table('jobs')->pluck('payload')->implode("\n")
        ."\n".DB::table('failed_jobs')->pluck('payload')->implode("\n")
        ."\n".DB::table('failed_jobs')->pluck('exception')->implode("\n");
    $clean = $row instanceof EmailOutbox
        && $row->status === EmailStatus::Sent
        && $row->secrets === null
        && ! password_reset_contains($body, $token)
        && ! password_reset_contains($raw_secrets, $token)
        && ! password_reset_contains($queue, $token);

    expect($clean)->toBeTrue()
        ->and($flags['leaked'])->toBeFalse()
        ->and($flags['shaped'])->toBeTrue();
});

it('clears secrets on an older unsent reset when a new link is issued', function () {
    $student = password_reset_student('ada.again@example.com', 'CSC/2026/0046');
    $sentinel = 'queued-reset-secret';
    $other = password_reset_student('ada.other@example.com', 'CSC/2026/0047');
    $old = EmailOutbox::factory()->create([
        'user_id' => $student->user->id,
        'recipient_email' => $student->user->email,
        'template' => 'password_reset',
        'subject' => 'Reset your password',
        'body_html' => OutboundMessage::HELD_BODY,
        'body_text' => OutboundMessage::HELD_BODY,
        'secrets' => ['name' => 'Ada Reset', 'token' => $sentinel],
        'status' => EmailStatus::Queued,
    ]);
    $kept = EmailOutbox::factory()->create([
        'user_id' => $other->user->id,
        'recipient_email' => $other->user->email,
        'template' => 'password_reset',
        'subject' => 'Reset your password',
        'body_html' => OutboundMessage::HELD_BODY,
        'body_text' => OutboundMessage::HELD_BODY,
        'secrets' => ['name' => 'Ada Reset', 'token' => $sentinel],
        'status' => EmailStatus::Queued,
    ]);

    password_reset_request($student->user->email, '203.0.113.22');

    $old->refresh();
    $kept->refresh();
    $old_raw = (string) $old->getRawOriginal('secrets');
    $old_present = password_reset_contains($old_raw, $sentinel)
        || password_reset_contains((string) $old->body_html, $sentinel)
        || (is_array($old->secrets) && (($old->secrets['token'] ?? null) === $sentinel));
    $kept_present = is_array($kept->secrets) && (($kept->secrets['token'] ?? null) === $sentinel);

    expect($old->status)->toBe(EmailStatus::Failed)
        ->and($old->last_error)->toBe(MailError::SUPERSEDED)
        ->and($old_present)->toBeFalse()
        ->and($kept->status)->toBe(EmailStatus::Queued)
        ->and($kept_present)->toBeTrue();
});

it('resets the password once and rejects the link after it is used or expired', function () {
    $student = password_reset_student('ada.once@example.com', 'CSC/2026/0048');
    $user = $student->user;
    $remember_before = $user->remember_token;

    password_reset_request($user->email, '203.0.113.30');
    $token = password_reset_token_from_mail();

    $this->travel(61)->minutes();

    password_reset_submit($token, $user->email, password_reset_new_password(), '203.0.113.31')
        ->assertRedirect();

    expect(Hash::check('Portal-pass-1', (string) $user->fresh()->password))->toBeTrue()
        ->and(AuditLog::query()->count())->toBe(0);

    $this->travelBack();
    password_reset_request($user->email, '203.0.113.32');
    $fresh_token = password_reset_token_from_mail();

    $this->followingRedirects()
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.33'])
        ->post('/reset-password/'.$fresh_token, [
            'identifier' => 'csc/2026/0048',
            'password' => password_reset_new_password(),
            'password_confirmation' => password_reset_new_password(),
        ])
        ->assertSee(PasswordResetService::SUCCESS_MESSAGE);

    $user->refresh();
    $audit = AuditLog::query()->first();
    $audit_text = strtolower((string) json_encode($audit));
    $leaked = password_reset_contains($audit_text, strtolower($fresh_token))
        || password_reset_contains($audit_text, strtolower(password_reset_new_password()))
        || password_reset_contains($audit_text, 'csc/2026/0048')
        || password_reset_contains($audit_text, 'ada.once@example.com');

    expect($this->isAuthenticated())->toBeFalse()
        ->and(Hash::check(password_reset_new_password(), (string) $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeFalse()
        ->and($user->temp_password_expires_at)->toBeNull()
        ->and($user->remember_token)->not->toBe($remember_before)
        ->and(AuditLog::query()->count())->toBe(1)
        ->and($audit?->action)->toBe('auth.password_reset')
        ->and($audit?->entity_id)->toBe($user->id)
        ->and($leaked)->toBeFalse();

    password_reset_submit($fresh_token, $user->email, 'Portal-pass-3', '203.0.113.34');

    expect(Hash::check(password_reset_new_password(), (string) $user->fresh()->password))->toBeTrue()
        ->and(AuditLog::query()->count())->toBe(1);

    password_reset_login($user->email, password_reset_new_password(), '203.0.113.35')
        ->assertRedirect('/student');
});

it('rejects a password that breaks the portal rules', function () {
    $student = password_reset_student('ada.rules@example.com', 'CSC/2026/0049');
    password_reset_request($student->user->email, '203.0.113.36');
    $token = password_reset_token_from_mail();

    foreach (['short1', 'abcdefghij', '1234567890', str_repeat('a', 72).'1'] as $password) {
        password_reset_submit($token, $student->user->email, $password, '203.0.113.36')
            ->assertSessionHasErrors(['password' => PasswordResetService::PASSWORD_MESSAGE]);
    }

    password_reset_submit($token, $student->user->email, 'x'.$student->user->email.'9', '203.0.113.37')
        ->assertSessionHasErrors(['password' => PasswordResetService::PASSWORD_MESSAGE]);

    password_reset_submit($token, $student->user->email, 'Portal-CSC/2026/0049', '203.0.113.38')
        ->assertSessionHasErrors(['password' => PasswordResetService::PASSWORD_MESSAGE]);

    password_reset_submit($token, $student->user->email, 'Portal-pass-1', '203.0.113.39')
        ->assertSessionHasErrors(['password' => PasswordResetService::PASSWORD_MESSAGE]);

    password_reset_submit($token, 'nobody@example.com', 'short1', '203.0.113.39')
        ->assertSessionHasErrors(['password' => PasswordResetService::PASSWORD_MESSAGE]);

    expect(Hash::check('Portal-pass-1', (string) $student->user->fresh()->password))->toBeTrue()
        ->and(DB::table('password_reset_tokens')->where('email', $student->user->email)->exists())->toBeTrue();
});

it('deletes every session and lets an expired temporary password sign in', function () {
    config(['session.driver' => 'database']);

    $student = password_reset_student('ada.temp@example.com', 'CSC/2026/0050', [
        'must_change_password' => true,
        'temp_password_expires_at' => now()->subMinute(),
    ]);
    $user = $student->user;
    $remember_before = $user->remember_token;

    password_reset_login($user->email, 'Portal-pass-1', '203.0.113.41')
        ->assertSessionHasErrors(['identifier' => AuthService::FAILURE_MESSAGE]);

    password_reset_request($user->email, '203.0.113.42');
    $token = password_reset_token_from_mail();

    foreach (['session-a', 'session-b'] as $session_id) {
        DB::table('sessions')->insert([
            'id' => $session_id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'other-browser',
            'payload' => base64_encode('other'),
            'last_activity' => time(),
        ]);
    }

    $this->get('/reset-password/'.$token);
    $page = $this->get('/reset-password/'.$token);
    $shown = password_reset_contains($page->getContent(), $token);

    expect($shown)->toBeFalse()
        ->and(str_contains((string) $page->headers->get('Cache-Control'), 'no-store'))->toBeTrue()
        ->and($page->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($page->getContent())->not->toContain('fonts.googleapis')
        ->and($page->getContent())->not->toContain('cdn.');

    $reset = password_reset_submit($token, $user->email, password_reset_new_password(), '203.0.113.43');

    expect(password_reset_landed_on_login($reset))->toBeTrue();

    $user->refresh();

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($user->remember_token)->not->toBe($remember_before)
        ->and($user->must_change_password)->toBeFalse()
        ->and($user->temp_password_expires_at)->toBeNull()
        ->and($this->isAuthenticated())->toBeFalse();

    password_reset_login($user->email, password_reset_new_password(), '203.0.113.44')
        ->assertRedirect('/student');
});

it('clears secrets still queued for that user after a successful reset', function () {
    $student = password_reset_student('ada.queue@example.com', 'CSC/2026/0051');
    $sentinel = 'still-queued-secret';

    password_reset_request($student->user->email, '203.0.113.45');
    $token = password_reset_token_from_mail();

    $queued = EmailOutbox::factory()->create([
        'user_id' => $student->user->id,
        'recipient_email' => $student->user->email,
        'template' => 'password_reset',
        'subject' => 'Reset your password',
        'body_html' => OutboundMessage::HELD_BODY,
        'body_text' => OutboundMessage::HELD_BODY,
        'secrets' => ['name' => 'Ada Reset', 'token' => $sentinel],
        'status' => EmailStatus::Queued,
    ]);

    $reset = password_reset_submit($token, $student->user->email, password_reset_new_password(), '203.0.113.46');

    expect(password_reset_landed_on_login($reset))->toBeTrue();

    $queued->refresh();
    $raw = (string) $queued->getRawOriginal('secrets');
    $present = password_reset_contains($raw, $sentinel)
        || password_reset_contains((string) $queued->body_html, $sentinel)
        || (is_array($queued->secrets) && (($queued->secrets['token'] ?? null) === $sentinel));

    expect($queued->status)->toBe(EmailStatus::Failed)
        ->and($queued->last_error)->toBe(MailError::SUPERSEDED)
        ->and($present)->toBeFalse();
});

it('throttles reset requests on the database cache without a different response', function () {
    password_reset_use_database_cache();
    $student = password_reset_student('ada.throttle@example.com', 'CSC/2026/0052');

    $first = null;

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $first = password_reset_request($student->user->email, '203.0.113.50', '198.51.100.'.$attempt);
    }

    $blocked = password_reset_request($student->user->email, '203.0.113.50', '198.51.100.99');

    expect(EmailOutbox::query()->where('user_id', $student->user->id)->count())->toBe(5)
        ->and($blocked->status())->toBe($first?->status())
        ->and(password_reset_visible_html($blocked->getContent()))->toBe(password_reset_visible_html((string) $first?->getContent()));

    password_reset_request($student->user->email, '203.0.113.51', '198.51.100.99');

    expect(EmailOutbox::query()->where('user_id', $student->user->id)->count())->toBe(6);
});

it('throttles reset submissions on the database cache with the same failure', function () {
    password_reset_use_database_cache();
    $student = password_reset_student('ada.submit@example.com', 'CSC/2026/0053');

    password_reset_request($student->user->email, '203.0.113.60');
    $token = password_reset_token_from_mail();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        password_reset_submit($token.'extra', $student->user->email, password_reset_new_password(), '203.0.113.61')
            ->assertSessionHas('status', PasswordResetService::FAILURE_MESSAGE);
    }

    password_reset_submit($token, $student->user->email, 'short1', '203.0.113.61')
        ->assertSessionHas('status', PasswordResetService::FAILURE_MESSAGE)
        ->assertSessionDoesntHaveErrors('password');

    expect(Hash::check('Portal-pass-1', (string) $student->user->fresh()->password))->toBeTrue();

    $student_b = password_reset_student('ada.submitb@example.com', 'CSC/2026/0054');
    password_reset_request($student_b->user->email, '203.0.113.62');
    $second_token = password_reset_token_from_mail();

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        password_reset_submit($second_token.'no', $student_b->user->email, password_reset_new_password(), '203.0.113.'.(70 + $attempt));
    }

    password_reset_submit($second_token, $student_b->user->email, password_reset_new_password(), '203.0.113.90')
        ->assertSessionHas('status', PasswordResetService::FAILURE_MESSAGE);

    expect(Hash::check('Portal-pass-1', (string) $student_b->user->fresh()->password))->toBeTrue();
});

it('lets a locked-out user reset and then sign in', function () {
    password_reset_use_database_cache();
    $student = password_reset_student('ada.locked@example.com', 'CSC/2026/0055');
    $email = $student->user->email;
    $hmac = hash_hmac('sha256', $email, (string) config('app.key'));
    $matric_hmac = hash_hmac('sha256', 'CSC/2026/0055', (string) config('app.key'));

    for ($attempt = 0; $attempt < 5; $attempt++) {
        password_reset_login($email, 'wrong-password-1', '203.0.113.1');
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        password_reset_login($email, 'wrong-password-1', '203.0.113.2');
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        password_reset_login('CSC/2026/0055', 'wrong-password-1', '203.0.113.1');
    }

    password_reset_login($email, 'Portal-pass-1', '203.0.113.3')
        ->assertSessionHasErrors(['identifier' => AuthService::FAILURE_MESSAGE]);

    password_reset_request('nobody.locked@example.com', '203.0.113.8');

    expect(RateLimiterFacade::tooManyAttempts('login-identifier:'.$hmac, 10))->toBeTrue()
        ->and(RateLimiterFacade::tooManyAttempts('login-pair:'.$matric_hmac.':203.0.113.1', 5))->toBeTrue();

    password_reset_login($email, 'Portal-pass-1', '203.0.113.3')
        ->assertSessionHasErrors(['identifier' => AuthService::FAILURE_MESSAGE]);

    password_reset_request($email, '203.0.113.4');
    $token = password_reset_token_from_mail();

    $reset = password_reset_submit($token, $email, password_reset_new_password(), '203.0.113.5');

    expect(password_reset_landed_on_login($reset))->toBeTrue();

    expect(RateLimiterFacade::tooManyAttempts('login-identifier:'.$hmac, 10))->toBeFalse()
        ->and(RateLimiterFacade::tooManyAttempts('login-pair:'.$hmac.':203.0.113.1', 5))->toBeFalse()
        ->and(RateLimiterFacade::tooManyAttempts('login-pair:'.$matric_hmac.':203.0.113.1', 5))->toBeFalse();

    password_reset_login($email, password_reset_new_password(), '203.0.113.1')
        ->assertRedirect('/student');

    test()->post('/logout')->assertRedirect('/login');

    password_reset_login('CSC/2026/0055', password_reset_new_password(), '203.0.113.1')
        ->assertRedirect('/student');
});

it('rolls back the password and the audit row together', function () {
    $student = password_reset_student('ada.rollback@example.com', 'CSC/2026/0056');
    password_reset_request($student->user->email, '203.0.113.91');
    $token = password_reset_token_from_mail();
    $armed = true;

    DB::listen(function ($query) use (&$armed): void {
        if ($armed && str_contains(strtolower($query->sql), 'audit_logs')) {
            $armed = false;

            throw new RuntimeException('rolled_back');
        }
    });

    password_reset_submit($token, $student->user->email, password_reset_new_password(), '203.0.113.92')
        ->assertStatus(500);

    expect(Hash::check('Portal-pass-1', (string) $student->user->fresh()->password))->toBeTrue()
        ->and(AuditLog::query()->count())->toBe(0);

    password_reset_login($student->user->email, 'Portal-pass-1', '203.0.113.93')
        ->assertRedirect('/student');
});

it('sends no-store and no-referrer and leaves Filament password reset off', function () {
    $forgot = $this->get('/forgot-password');
    $reset = $this->get('/reset-password/notasecret');
    $names = [];

    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();

        if (is_string($name) && str_contains($name, 'password-reset')) {
            $names[] = $name;
        }
    }

    expect(str_contains((string) $forgot->headers->get('Cache-Control'), 'no-store'))->toBeTrue()
        ->and($forgot->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and(str_contains((string) $reset->headers->get('Cache-Control'), 'no-store'))->toBeTrue()
        ->and($reset->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($forgot->getContent())->not->toContain('fonts.googleapis')
        ->and($reset->getContent())->not->toContain('fonts.googleapis')
        ->and($names)->toBe([]);
});
