<?php

declare(strict_types=1);

use App\Enums\EmailStatus;
use App\Jobs\SendOutboxEmail;
use App\Models\EmailOutbox;
use App\Models\User;
use App\Services\MailService;
use App\Support\Mail\MailError;
use App\Support\Mail\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'mail.daily_limit' => null,
        'mail.outbox_batch' => 25,
        'mail.default' => 'array',
    ]);
    Cache::store('database')->forget(MailService::DAILY_LIMIT_KEY);
    Cache::store('database')->forget(MailService::CIRCUIT_PAUSE_KEY);
    Cache::store('database')->forget(MailService::CIRCUIT_STREAK_KEY);
});

/**
 * Whether the needle appears. A failure of the boolean does not print it.
 */
function outbox_text_has(string $haystack, string $needle): bool
{
    return $needle !== '' && str_contains($haystack, $needle);
}

/**
 * Queue a credentials row without running the send job.
 */
function outbox_queue_credentials(string $password): EmailOutbox
{
    $user = User::factory()->create();

    return app(MailService::class)->send(
        OutboundMessage::TEMPLATE_CREDENTIALS,
        'student@example.com',
        [
            'name' => 'Ada Lovelace',
            'login_id' => 'MAT-2026-0049',
            'temporary_password' => $password,
            'expires_at' => '2026-10-17 14:30:00',
        ],
        $user->id,
    );
}

it('dispatches only the batch size and leaves the rest queued', function () {
    Queue::fake();
    config(['mail.outbox_batch' => 2]);

    $rows = EmailOutbox::factory()->count(4)->create([
        'recipient_email' => 'batch-person@example.test',
        'body_text' => 'Batch notice',
        'attempts' => 0,
    ]);

    $exit = Artisan::call('outbox:send');
    $output = Artisan::output();
    $leaked = outbox_text_has($output, 'batch-person@example.test')
        || outbox_text_has($output, 'Batch notice');

    expect($exit)->toBe(0)
        ->and($leaked)->toBeFalse()
        ->and($output)->toContain('dispatched=2')
        ->and(Queue::pushed(SendOutboxEmail::class))->toHaveCount(2);

    expect(EmailOutbox::query()->where('status', EmailStatus::Queued)->count())->toBe(4)
        ->and($rows->every(fn (EmailOutbox $row): bool => $row->refresh()->status === EmailStatus::Queued))->toBeTrue();
});

it('honours the daily send limit and leaves the rest queued', function () {
    Queue::fake();
    config(['mail.daily_limit' => 1]);

    EmailOutbox::factory()->sent()->create([
        'sent_at' => now(),
    ]);
    EmailOutbox::factory()->count(2)->create([
        'attempts' => 0,
    ]);

    $this->artisan('outbox:send')->assertSuccessful();

    expect(Queue::pushed(SendOutboxEmail::class))->toHaveCount(0)
        ->and(EmailOutbox::query()->where('status', EmailStatus::Queued)->count())->toBe(2)
        ->and(app(MailService::class)->dailyLimitReached())->toBeTrue();
});

it('stops dispatching once this run would reach the daily cap', function () {
    Queue::fake();
    config(['mail.daily_limit' => 2, 'mail.outbox_batch' => 25]);

    EmailOutbox::factory()->count(3)->create([
        'attempts' => 0,
    ]);

    $this->artisan('outbox:send')->assertSuccessful();

    expect(Queue::pushed(SendOutboxEmail::class))->toHaveCount(2)
        ->and(app(MailService::class)->dailyLimitReached())->toBeTrue()
        ->and(EmailOutbox::query()->where('status', EmailStatus::Queued)->count())->toBe(3);
});

it('does not dispatch a row that is still inside its backoff', function () {
    Queue::fake();

    $waiting = EmailOutbox::factory()->create(['attempts' => 1]);
    $due = EmailOutbox::factory()->create(['attempts' => 1]);
    DB::table('email_outbox')->where('id', $waiting->id)->update(['updated_at' => now()]);
    DB::table('email_outbox')->where('id', $due->id)->update(['updated_at' => now()->subMinutes(2)]);

    $this->artisan('outbox:send')->assertSuccessful();

    $pushed = Queue::pushed(SendOutboxEmail::class)->map(
        fn (SendOutboxEmail $job): int => $job->email_outbox_id,
    )->all();

    expect($pushed)->toBe([$due->id])
        ->and($waiting->refresh()->status)->toBe(EmailStatus::Queued);
});

it('does not dispatch a row whose send lock is held', function () {
    Queue::fake();

    $locked = EmailOutbox::factory()->create(['attempts' => 0]);
    $free = EmailOutbox::factory()->create(['attempts' => 0]);
    $lock = Cache::store('database')->lock(MailService::LOCK_PREFIX.$locked->id, 30);

    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('outbox:send')->assertSuccessful();
    } finally {
        $lock->release();
    }

    $pushed = Queue::pushed(SendOutboxEmail::class)->map(
        fn (SendOutboxEmail $job): int => $job->email_outbox_id,
    )->all();

    expect($pushed)->toBe([$free->id])
        ->and($locked->refresh()->status)->toBe(EmailStatus::Queued);
});

it('fails a credentials row that has used every attempt and clears the secret', function () {
    Queue::fake();

    $password = 'Exhaust-command-91';
    $row = outbox_queue_credentials($password);
    Queue::fake();
    DB::table('email_outbox')->where('id', $row->id)->update([
        'attempts' => 5,
        'updated_at' => now()->subHour(),
    ]);

    $exit = Artisan::call('outbox:send');
    $output = Artisan::output();
    $row->refresh();
    $raw = DB::table('email_outbox')->where('id', $row->id)->value('secrets');
    $leaked = outbox_text_has((string) $row->body_html, $password)
        || outbox_text_has((string) $row->body_text, $password)
        || outbox_text_has((string) $row->last_error, $password)
        || outbox_text_has((string) $raw, $password)
        || outbox_text_has($output, $password)
        || outbox_text_has($output, 'student@example.com');

    expect($exit)->toBe(0)
        ->and($row->status)->toBe(EmailStatus::Failed)
        ->and($row->last_error)->toBe(MailError::PROVIDER_UNAVAILABLE)
        ->and($raw === null)->toBeTrue()
        ->and($leaked)->toBeFalse()
        ->and(Queue::pushed(SendOutboxEmail::class))->toHaveCount(0);
});

it('queues one job when the same row is dispatched twice', function () {
    Queue::fake();

    $row = EmailOutbox::factory()->create();
    SendOutboxEmail::dispatch($row->id);
    SendOutboxEmail::dispatch($row->id);

    expect(Queue::pushed(SendOutboxEmail::class))->toHaveCount(1);
});

it('schedules outbox send every minute', function () {
    $events = collect(Schedule::events())->filter(
        fn (object $event): bool => str_contains((string) ($event->command ?? ''), 'outbox:send'),
    );

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});
