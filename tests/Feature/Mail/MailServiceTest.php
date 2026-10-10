<?php

declare(strict_types=1);

use App\Enums\EmailStatus;
use App\Jobs\SendOutboxEmail;
use App\Models\EmailOutbox;
use App\Models\User;
use App\Services\MailService;
use App\Support\Mail\MailError;
use App\Support\Mail\OutboundMessage;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Whether the needle appears in the haystack.
 */
function mail_contains(string $haystack, string $needle): bool
{
    return $needle !== '' && str_contains($haystack, $needle);
}

/**
 * Text from queue tables. Used so a failure does not print a secret.
 */
function mail_queue_text(): string
{
    $failed = DB::table('failed_jobs')->get()->map(
        fn (object $row): string => (string) $row->payload.' '.(string) $row->exception,
    )->implode("\n");
    $jobs = DB::table('jobs')->get()->map(
        fn (object $row): string => (string) $row->payload,
    )->implode("\n");

    return $failed."\n".$jobs;
}

/**
 * Point the mailer at the faked Brevo host.
 */
function mail_use_brevo(string $api_key = 'configured-mail-key'): void
{
    config([
        'mail.default' => 'brevo',
        'mail.mailers.brevo.transport' => 'brevo',
        'mail.mailers.brevo.api_url' => 'https://api.brevo.com',
        'mail.mailers.brevo.key' => $api_key,
        'mail.mailers.brevo.timeout' => 10,
        'mail.daily_limit' => 250,
    ]);
    Mail::purge('brevo');
    Cache::store('database')->forget(MailService::CIRCUIT_PAUSE_KEY);
    Cache::store('database')->forget(MailService::CIRCUIT_STREAK_KEY);
}

/**
 * Queue a credentials email without printing the payload.
 */
function mail_queue_credentials(string $password, string $login_id, ?User $user = null): EmailOutbox
{
    $user ??= User::factory()->create();

    return app(MailService::class)->send(
        OutboundMessage::TEMPLATE_CREDENTIALS,
        'student@example.com',
        [
            'name' => 'Ada Lovelace',
            'login_id' => $login_id,
            'temporary_password' => $password,
            'expires_at' => '2026-10-17 14:30:00',
        ],
        $user->id,
    );
}

/**
 * Run outside RefreshDatabase's wrapping transaction, then remove committed rows.
 *
 * @param  callable(): void  $callback
 */
function mail_outside_wrapping_transaction(callable $callback): void
{
    $connection = DB::connection();
    $wrapped = $connection->transactionLevel() > 0;

    if ($wrapped) {
        $connection->rollBack();
    }

    try {
        $callback();
    } finally {
        DB::table('email_outbox')->delete();
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();
        DB::table('cache')->delete();
        DB::table('cache_locks')->delete();
        DB::table('users')->delete();

        if ($wrapped && $connection->transactionLevel() === 0) {
            $connection->beginTransaction();
        }
    }
}

it('records a queued outbox row', function () {
    Queue::fake();

    $row = app(MailService::class)->send(OutboundMessage::TEMPLATE_TEST, 'ada@example.com', [
        'subject' => 'Hello',
        'message' => 'Campus notice',
    ]);

    expect($row->status)->toBe(EmailStatus::Queued)
        ->and($row->attempts)->toBe(0)
        ->and($row->sent_at)->toBeNull()
        ->and($row->secrets)->toBeNull()
        ->and($row->body_html)->toBe('<p>Campus notice</p>')
        ->and($row->body_text)->toBe('Campus notice');
});

it('stores a credentials payload only in secrets', function () {
    Queue::fake();

    $password = 'Stored-secret-91';
    $matric = 'MAT-2026-0001';
    $name = 'Ada Lovelace';
    $login = rtrim((string) config('app.url'), '/').'/login';
    $row = mail_queue_credentials($password, $matric);
    $raw = (string) DB::table('email_outbox')->where('id', $row->id)->value('secrets');
    $encoded = (string) json_encode($row->toArray());
    $leaked = mail_contains($row->body_html, $password)
        || mail_contains($row->body_text, $password)
        || mail_contains($row->body_html, $matric)
        || mail_contains($row->body_text, $matric)
        || mail_contains($row->body_html, $name)
        || mail_contains($row->body_text, $name)
        || mail_contains($row->body_html, $login)
        || mail_contains($row->body_text, $login)
        || mail_contains($raw, $password)
        || mail_contains($raw, $matric)
        || mail_contains($row->subject, $password)
        || mail_contains($encoded, $password);
    $holds_password = is_array($row->secrets) && ($row->secrets['temporary_password'] ?? null) === $password;

    expect($leaked)->toBeFalse()
        ->and($holds_password)->toBeTrue()
        ->and($row->body_html)->toBe(OutboundMessage::HELD_BODY)
        ->and($row->body_text)->toBe(OutboundMessage::HELD_BODY)
        ->and($row->status)->toBe(EmailStatus::Queued);
});

it('marks a secret email sent and redacts the body', function () {
    Queue::fake();

    $password = 'Sent-secret-91';
    $row = mail_queue_credentials($password, 'MAT-2026-0002');

    app(MailService::class)->deliver($row->id);
    $row->refresh();

    $leaked = mail_contains($row->body_html, $password) || mail_contains($row->body_text, $password);

    expect($row->status)->toBe(EmailStatus::Sent)
        ->and($row->sent_at)->not->toBeNull()
        ->and($row->redacted_at)->not->toBeNull()
        ->and($row->secrets)->toBeNull()
        ->and($row->body_html)->toBe(OutboundMessage::REDACTED_BODY)
        ->and($row->body_text)->toBe(OutboundMessage::REDACTED_BODY)
        ->and($leaked)->toBeFalse();
});

it('keeps an ordinary body after send', function () {
    Queue::fake();

    $row = app(MailService::class)->send(OutboundMessage::TEMPLATE_TEST, 'ada@example.com', [
        'subject' => 'Hello',
        'message' => 'Campus notice',
    ]);

    app(MailService::class)->deliver($row->id);
    $row->refresh();

    expect($row->status)->toBe(EmailStatus::Sent)
        ->and($row->sent_at)->not->toBeNull()
        ->and($row->redacted_at)->toBeNull()
        ->and($row->secrets)->toBeNull()
        ->and($row->body_html)->toBe('<p>Campus notice</p>')
        ->and($row->body_text)->toBe('Campus notice');
});

it('escapes user text in a test email', function () {
    Queue::fake();

    $row = app(MailService::class)->send(OutboundMessage::TEMPLATE_TEST, 'ada@example.com', [
        'subject' => 'Hello',
        'message' => '<script>',
    ]);

    expect(mail_contains($row->body_html, '<script>'))->toBeFalse()
        ->and(mail_contains($row->body_html, '&lt;script&gt;'))->toBeTrue();
});

it('increments attempts and stays queued after a temporary failure', function () {
    Queue::fake();
    mail_use_brevo();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response('', 503),
    ]);

    $password = 'Retry-secret-91';
    $row = mail_queue_credentials($password, 'MAT-2026-0003');
    $queued_before = Queue::pushed(SendOutboxEmail::class)->count();

    app(MailService::class)->deliver($row->id);
    $row->refresh();
    app(MailService::class)->deliver($row->id);
    $row->refresh();

    $kept = is_array($row->secrets) && ($row->secrets['temporary_password'] ?? null) === $password;

    expect($row->status)->toBe(EmailStatus::Queued)
        ->and($row->attempts)->toBe(1)
        ->and($row->last_error)->toBe(MailError::PROVIDER_UNAVAILABLE)
        ->and($row->redacted_at)->toBeNull()
        ->and($kept)->toBeTrue()
        ->and(Queue::pushed(SendOutboxEmail::class))->toHaveCount($queued_before);
});

it('fails a permanent provider rejection at once and clears the secret', function () {
    Queue::fake();
    mail_use_brevo();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response('', 422),
    ]);

    $row = mail_queue_credentials('Reject-secret-91', 'MAT-2026-0004');

    app(MailService::class)->deliver($row->id);
    $row->refresh();

    expect($row->status)->toBe(EmailStatus::Failed)
        ->and($row->attempts)->toBe(0)
        ->and($row->last_error)->toBe(MailError::PROVIDER_REJECTED)
        ->and($row->secrets)->toBeNull()
        ->and($row->redacted_at)->toBeNull()
        ->and(Cache::store('database')->get(MailService::CIRCUIT_PAUSE_KEY))->not->toBeTrue();
});

it('clears the secret when attempts are exhausted', function () {
    Queue::fake();
    mail_use_brevo();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response('', 503),
    ]);

    $password = 'Exhaust-secret-91';
    $row = mail_queue_credentials($password, 'MAT-2026-0005');
    DB::table('email_outbox')->where('id', $row->id)->update([
        'attempts' => 4,
        'updated_at' => now()->subHours(2),
    ]);

    app(MailService::class)->deliver($row->id);
    $row->refresh();

    $leaked = mail_contains($row->body_html, $password) || mail_contains($row->body_text, $password);

    expect($row->status)->toBe(EmailStatus::Failed)
        ->and($row->attempts)->toBe(5)
        ->and($row->secrets)->toBeNull()
        ->and($row->redacted_at)->toBeNull()
        ->and($leaked)->toBeFalse();
});

it('clears the secret when a re-issue supersedes the row', function () {
    Queue::fake();

    $user = User::factory()->create();
    $first_password = 'First-secret-91';
    $second_password = 'Second-secret-92';
    $first = mail_queue_credentials($first_password, 'MAT-2026-0006', $user);
    $second = mail_queue_credentials($second_password, 'MAT-2026-0006', $user);
    $first->refresh();

    $first_leaked = mail_contains($first->body_html, $first_password)
        || mail_contains($first->body_text, $first_password);
    $second_leaked = mail_contains($second->body_html, $second_password)
        || mail_contains($second->body_text, $second_password);
    $second_holds = is_array($second->secrets) && ($second->secrets['temporary_password'] ?? null) === $second_password;

    expect($first->status)->toBe(EmailStatus::Failed)
        ->and($first->last_error)->toBe(MailError::SUPERSEDED)
        ->and($first->secrets)->toBeNull()
        ->and($first->redacted_at)->toBeNull()
        ->and($first_leaked)->toBeFalse()
        ->and($second->status)->toBe(EmailStatus::Queued)
        ->and($second_holds)->toBeTrue()
        ->and($second_leaked)->toBeFalse();
});

it('puts only the outbox id in the job payload', function () {
    mail_outside_wrapping_transaction(function (): void {
        Queue::fake();

        $password = 'Payload-secret-91';
        $address = 'payload-person@example.test';
        $user = User::factory()->create();
        $row = app(MailService::class)->send(
            OutboundMessage::TEMPLATE_CREDENTIALS,
            $address,
            [
                'name' => 'Ada Lovelace',
                'login_id' => 'MAT-2026-0007',
                'temporary_password' => $password,
                'expires_at' => '2026-10-17 14:30:00',
            ],
            $user->id,
        );

        Queue::assertPushed(SendOutboxEmail::class, function (SendOutboxEmail $job) use ($row, $password, $address): bool {
            $encoded = serialize($job);
            $leaked = mail_contains($encoded, $password) || mail_contains($encoded, $address);

            expect($leaked)->toBeFalse()
                ->and($job->email_outbox_id)->toBe($row->id);

            return true;
        });
    });
});

it('keeps the response body out of the log, last_error, and failed jobs', function () {
    Queue::fake();

    $address = 'leak-marker-address@example.test';
    $api_key = 'leak-marker-key-9f3a';
    $logged = '';
    $log_path = storage_path('logs/laravel.log');
    $log_before = is_file($log_path) ? (string) file_get_contents($log_path) : '';

    Log::listen(function (MessageLogged $event) use (&$logged): void {
        $encoded = json_encode([$event->message, $event->context]);

        if (is_string($encoded)) {
            $logged .= $encoded;
        }
    });

    mail_use_brevo($api_key);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response(
            '{"message":"'.$address.'","key":"'.$api_key.'"}',
            400,
        ),
    ]);

    $user = User::factory()->create();
    $row = app(MailService::class)->send(
        OutboundMessage::TEMPLATE_CREDENTIALS,
        $address,
        [
            'name' => 'Ada Lovelace',
            'login_id' => 'MAT-2026-0008',
            'temporary_password' => 'Leak-secret-91',
            'expires_at' => '2026-10-17 14:30:00',
        ],
        $user->id,
    );

    app(MailService::class)->deliver($row->id);
    $row->refresh();

    $log_after = is_file($log_path) ? (string) file_get_contents($log_path) : '';
    $log_added = strlen($log_after) > strlen($log_before) ? substr($log_after, strlen($log_before)) : '';
    $stored = mail_queue_text()."\n".(string) $row->last_error."\n".$logged."\n".$log_added;
    $leaked = mail_contains($stored, $address) || mail_contains($stored, $api_key);

    expect($leaked)->toBeFalse()
        ->and($row->status)->toBe(EmailStatus::Failed)
        ->and($row->last_error)->toBe(MailError::INVALID_RECIPIENT)
        ->and($row->secrets)->toBeNull();
});

it('sends one email when two workers take the same row', function () {
    mail_outside_wrapping_transaction(function (): void {
        Queue::fake();

        $store = Cache::store('database')->getStore();
        expect($store)->toBeInstanceOf(DatabaseStore::class)
            ->and(Schema::hasTable('cache_locks'))->toBeTrue();

        $row = app(MailService::class)->send(OutboundMessage::TEMPLATE_TEST, 'ada@example.com', [
            'subject' => 'Hello',
            'message' => 'Notice',
        ]);
        $key = $store->getPrefix().MailService::LOCK_PREFIX.$row->id;
        $seen_lock = false;

        Event::listen(MessageSending::class, function (MessageSending $event) use (&$seen_lock, $key, $row): void {
            unset($event);

            if ($seen_lock) {
                return;
            }

            $seen_lock = DB::table('cache_locks')->where('key', $key)->exists();
            app(MailService::class)->deliver($row->id);
        });

        app(MailService::class)->deliver($row->id);
        $row->refresh();

        $transport = Mail::mailer('array')->getSymfonyTransport();
        $sent_once = $transport instanceof ArrayTransport && $transport->messages()->count() === 1;

        expect($seen_lock)->toBeTrue()
            ->and($sent_once)->toBeTrue()
            ->and($row->status)->toBe(EmailStatus::Sent)
            ->and(Queue::pushed(SendOutboxEmail::class))->toHaveCount(1);
    });
});

it('refuses credential and reset templates on the log mailer in production', function () {
    Queue::fake();

    $password = 'Guard-secret-91';
    $token = 'Guard-token-91';
    $logged = '';

    Log::listen(function (MessageLogged $event) use (&$logged): void {
        $encoded = json_encode([$event->message, $event->context]);

        if (is_string($encoded)) {
            $logged .= $encoded;
        }
    });

    $credentials = mail_queue_credentials($password, 'MAT-2026-0009');
    $user = User::factory()->create();
    $reset = app(MailService::class)->send(
        OutboundMessage::TEMPLATE_PASSWORD_RESET,
        'student@example.com',
        [
            'name' => 'Ada Lovelace',
            'reset_url' => rtrim((string) config('app.url'), '/').'/reset',
            'token' => $token,
        ],
        $user->id,
    );
    $reset_stored = mail_contains($reset->body_html, $token) || mail_contains($reset->body_text, $token);

    expect($reset_stored)->toBeFalse()
        ->and($reset->body_html)->toBe(OutboundMessage::HELD_BODY);

    app()['env'] = 'production';
    config(['mail.default' => 'log']);

    app(MailService::class)->deliver($credentials->id);
    app(MailService::class)->deliver($reset->id);
    $credentials->refresh();
    $reset->refresh();

    $leaked = mail_contains($logged, $password)
        || mail_contains($logged, $token)
        || mail_contains((string) $credentials->last_error, $password)
        || mail_contains((string) $reset->last_error, $token);

    expect($credentials->status)->toBe(EmailStatus::Failed)
        ->and($reset->status)->toBe(EmailStatus::Failed)
        ->and($credentials->last_error)->toBe(MailError::SECRET_TEMPLATE_REFUSED)
        ->and($reset->last_error)->toBe(MailError::SECRET_TEMPLATE_REFUSED)
        ->and($credentials->secrets)->toBeNull()
        ->and($reset->secrets)->toBeNull()
        ->and($credentials->redacted_at)->toBeNull()
        ->and($reset->redacted_at)->toBeNull()
        ->and(Cache::store('database')->get(MailService::CIRCUIT_PAUSE_KEY))->not->toBeTrue()
        ->and($leaked)->toBeFalse();
});

it('removes line breaks from the subject', function () {
    Queue::fake();

    $row = app(MailService::class)->send(OutboundMessage::TEMPLATE_TEST, 'ada@example.com', [
        'subject' => "Hello\r\nthere",
        'message' => 'Notice',
    ]);

    expect(mail_contains($row->subject, "\r"))->toBeFalse()
        ->and(mail_contains($row->subject, "\n"))->toBeFalse()
        ->and($row->subject)->toBe('Hellothere');
});

it('keeps the password out of the subject, last_error, and logs', function () {
    Queue::fake();

    $password = 'Subject-secret-91';
    $logged = '';

    Log::listen(function (MessageLogged $event) use (&$logged): void {
        $encoded = json_encode([$event->message, $event->context]);

        if (is_string($encoded)) {
            $logged .= $encoded;
        }
    });

    $row = app(MailService::class)->send(OutboundMessage::TEMPLATE_TEST, 'ada@example.com', [
        'subject' => "Code\n".$password,
        'message' => 'Notice '.$password,
        'temporary_password' => $password,
    ]);

    app(MailService::class)->deliver($row->id);
    $row->refresh();

    $leaked = mail_contains($row->subject, $password)
        || mail_contains($row->body_html, $password)
        || mail_contains($row->body_text, $password)
        || mail_contains((string) $row->last_error, $password)
        || mail_contains($logged, $password);

    expect($leaked)->toBeFalse()
        ->and($row->secrets)->toBeNull()
        ->and($row->status)->toBe(EmailStatus::Sent);
});

it('does not pause sending after three consecutive 400 responses', function () {
    Queue::fake();
    mail_use_brevo();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::sequence()
            ->push('', 400)
            ->push('', 400)
            ->push('', 400)
            ->push('', 201),
    ]);

    $rows = [
        mail_queue_credentials('Four-secret-91', 'MAT-2026-0011'),
        mail_queue_credentials('Four-secret-92', 'MAT-2026-0012'),
        mail_queue_credentials('Four-secret-93', 'MAT-2026-0013'),
        mail_queue_credentials('Four-secret-94', 'MAT-2026-0014'),
    ];

    app(MailService::class)->deliver($rows[0]->id);
    app(MailService::class)->deliver($rows[1]->id);
    app(MailService::class)->deliver($rows[2]->id);

    $paused = Cache::store('database')->get(MailService::CIRCUIT_PAUSE_KEY) === true;

    app(MailService::class)->deliver($rows[3]->id);
    $rows[0]->refresh();
    $rows[3]->refresh();

    expect($paused)->toBeFalse()
        ->and($rows[0]->status)->toBe(EmailStatus::Failed)
        ->and($rows[0]->last_error)->toBe(MailError::INVALID_RECIPIENT)
        ->and($rows[0]->secrets)->toBeNull()
        ->and($rows[3]->status)->toBe(EmailStatus::Sent)
        ->and($rows[3]->secrets)->toBeNull();
});

it('pauses sending after three consecutive 401 responses', function () {
    Queue::fake();
    mail_use_brevo();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::sequence()
            ->push('', 401)
            ->push('', 401)
            ->push('', 401)
            ->push('', 201),
    ]);

    $password = 'Auth-secret-91';
    $rows = [
        mail_queue_credentials($password, 'MAT-2026-0021'),
        mail_queue_credentials('Auth-secret-92', 'MAT-2026-0022'),
        mail_queue_credentials('Auth-secret-93', 'MAT-2026-0023'),
        mail_queue_credentials('Auth-secret-94', 'MAT-2026-0024'),
    ];

    app(MailService::class)->deliver($rows[0]->id);
    app(MailService::class)->deliver($rows[1]->id);
    app(MailService::class)->deliver($rows[2]->id);
    app(MailService::class)->deliver($rows[3]->id);
    $rows[0]->refresh();
    $rows[3]->refresh();

    $kept = is_array($rows[0]->secrets) && ($rows[0]->secrets['temporary_password'] ?? null) === $password;
    $paused = Cache::store('database')->get(MailService::CIRCUIT_PAUSE_KEY) === true;

    expect($paused)->toBeTrue()
        ->and($rows[0]->status)->toBe(EmailStatus::Queued)
        ->and($rows[0]->attempts)->toBe(0)
        ->and($rows[0]->last_error)->toBe(MailError::PROVIDER_AUTH)
        ->and($kept)->toBeTrue()
        ->and($rows[3]->status)->toBe(EmailStatus::Queued)
        ->and($rows[3]->attempts)->toBe(0)
        ->and(count(Http::recorded()))->toBe(3);
});
