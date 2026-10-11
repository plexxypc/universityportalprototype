<?php

declare(strict_types=1);

use App\Enums\EmailStatus;
use App\Enums\Role;
use App\Filament\Staff\Pages\EmailOutboxPage;
use App\Jobs\SendOutboxEmail;
use App\Models\EmailOutbox;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\MailService;
use App\Support\Mail\MailError;
use App\Support\Mail\OutboundMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

uses(RefreshDatabase::class);

/**
 * Whether the needle appears. A failure of the boolean does not print it.
 */
function secret_hunt_has(string $haystack, string $needle): bool
{
    return $needle !== '' && str_contains($haystack, $needle);
}

/**
 * Run outside RefreshDatabase's wrapping transaction, then remove committed rows.
 *
 * @param  callable(): void  $callback
 */
function secret_hunt_commit(callable $callback): void
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
        DB::table('notifications')->delete();
        DB::table('audit_logs')->delete();
        DB::table('role_assignments')->delete();
        DB::table('sessions')->delete();
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

/**
 * An active Super Admin.
 */
function secret_hunt_admin(): User
{
    $admin = User::factory()->create();

    RoleAssignment::factory()->create([
        'user_id' => $admin->id,
        'role' => Role::SuperAdmin,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    return $admin;
}

/**
 * Queue one secret email. The body stored here is a placeholder.
 */
function secret_hunt_queue(string $template, User $user, string $password, string $token): EmailOutbox
{
    if ($template === OutboundMessage::TEMPLATE_PASSWORD_RESET) {
        return app(MailService::class)->send($template, $user->email, [
            'name' => 'Ada Lovelace',
            'token' => $token,
        ], $user->id);
    }

    return app(MailService::class)->send($template, $user->email, [
        'name' => 'Ada Lovelace',
        'login_id' => 'MAT-2026-9053',
        'temporary_password' => $password,
        'expires_at' => '2026-10-17 14:30:00',
    ], $user->id);
}

/**
 * Run the send job. True when the exception leaves the job.
 */
function secret_hunt_handle(int $email_outbox_id): bool
{
    try {
        (new SendOutboxEmail($email_outbox_id))->handle(app(MailService::class));
    } catch (Throwable) {
        return true;
    }

    return false;
}

/**
 * True when retry is refused and the exception text is kept for the sweep.
 *
 * @return array{refused: bool, detail: string}
 */
function secret_hunt_retry(EmailOutbox $row, User $admin): array
{
    $current = EmailOutbox::query()->find($row->id);

    if (! $current instanceof EmailOutbox) {
        return ['refused' => false, 'detail' => ''];
    }

    try {
        app(MailService::class)->retry($current, $admin, '127.0.0.1');
    } catch (AuthorizationException) {
        return ['refused' => true, 'detail' => ''];
    } catch (Throwable $exception) {
        return ['refused' => false, 'detail' => $exception->getMessage()];
    }

    return ['refused' => false, 'detail' => ''];
}

/**
 * Replace the array transport with one that throws an ordinary exception.
 */
function secret_hunt_install_throwing_transport(string $secret): bool
{
    $mailer = Mail::mailer('array');

    if (! $mailer instanceof Mailer) {
        return false;
    }

    $transport = new class($secret) implements TransportInterface
    {
        public function __construct(private readonly string $secret) {}

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new RuntimeException('mail transport failed '.$this->secret);
        }

        public function __toString(): string
        {
            return 'throwing';
        }
    };

    $mailer->setSymfonyTransport($transport);

    return true;
}

/**
 * Text already written to log files on disk.
 */
function secret_hunt_log_files(): string
{
    $directory = storage_path('logs');

    if (! is_dir($directory)) {
        return '';
    }

    $text = '';
    $names = scandir($directory);

    if ($names === false) {
        return '';
    }

    foreach ($names as $name) {
        $path = $directory.DIRECTORY_SEPARATOR.$name;

        if (! is_file($path)) {
            continue;
        }

        $contents = file_get_contents($path);

        if (is_string($contents)) {
            $text .= $contents;
        }
    }

    return $text;
}

/**
 * Concatenate the named columns. Used so a failure does not print a secret.
 *
 * @param  list<string>  $columns
 */
function secret_hunt_columns(string $table, array $columns): string
{
    return DB::table($table)->get()->map(function (object $row) use ($columns): string {
        $parts = [];

        foreach ($columns as $column) {
            $value = $row->{$column} ?? '';
            $parts[] = is_scalar($value) || $value === null ? (string) $value : '';
        }

        return implode(' ', $parts);
    })->implode("\n");
}

/**
 * Outbox bodies, raw secrets, decrypted secrets, and last_error.
 */
function secret_hunt_outbox_text(): string
{
    $text = secret_hunt_columns('email_outbox', [
        'subject',
        'body_html',
        'body_text',
        'secrets',
        'last_error',
    ]);

    foreach (EmailOutbox::query()->get() as $row) {
        try {
            $decoded = json_encode($row->secrets);

            if (is_string($decoded)) {
                $text .= $decoded;
            }
        } catch (Throwable) {
            $text .= '';
        }
    }

    return $text;
}

/**
 * Whether this failed row used the fixed code and no longer has a secret.
 */
function secret_hunt_failed_closed(int $email_outbox_id, string $code): bool
{
    $row = DB::table('email_outbox')->where('id', $email_outbox_id)->first();

    return $row !== null
        && $row->status === EmailStatus::Failed->value
        && $row->last_error === $code
        && $row->secrets === null;
}

/**
 * Whether this row was sent and its secret was cleared.
 */
function secret_hunt_was_sent(int $email_outbox_id): bool
{
    $row = DB::table('email_outbox')->where('id', $email_outbox_id)->first();

    return $row !== null
        && $row->status === EmailStatus::Sent->value
        && $row->secrets === null
        && $row->redacted_at !== null;
}

it('keeps a credentials password and a reset token out of the send path', function () {
    $test = $this;
    $password = 'HuntPw'.bin2hex(random_bytes(8));
    $token = 'HuntTk'.bin2hex(random_bytes(8));

    secret_hunt_commit(function () use ($test, $password, $token): void {
        config([
            'queue.default' => 'database',
            'mail.default' => 'array',
            'mail.daily_limit' => 250,
            'session.driver' => 'database',
        ]);
        Cache::store('database')->forget(MailService::DAILY_LIMIT_KEY);
        Cache::store('database')->forget(MailService::CIRCUIT_PAUSE_KEY);
        Cache::store('database')->forget(MailService::CIRCUIT_STREAK_KEY);

        $logged = '';

        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $encoded = json_encode([$event->message, $event->context]);

            if (is_string($encoded)) {
                $logged .= $encoded;
            }
        });

        $admin = secret_hunt_admin();
        $sent_user = User::factory()->create();
        $exhaust_user = User::factory()->create();
        $reissue_user = User::factory()->create();
        $boom_user = User::factory()->create();

        $sent_credentials = secret_hunt_queue(OutboundMessage::TEMPLATE_CREDENTIALS, $sent_user, $password, $token);
        $sent_reset = secret_hunt_queue(OutboundMessage::TEMPLATE_PASSWORD_RESET, $sent_user, $password, $token);
        $sent_now = app(MailService::class)->sendNow($sent_credentials, $admin, '127.0.0.1');

        $exit = Artisan::call('outbox:send');
        $command_output = Artisan::output();
        $seen_jobs = secret_hunt_columns('jobs', ['payload']);
        $escaped = secret_hunt_handle($sent_credentials->id) || secret_hunt_handle($sent_reset->id);

        $exhaust_credentials = secret_hunt_queue(OutboundMessage::TEMPLATE_CREDENTIALS, $exhaust_user, $password, $token);
        $exhaust_reset = secret_hunt_queue(OutboundMessage::TEMPLATE_PASSWORD_RESET, $exhaust_user, $password, $token);
        DB::table('email_outbox')->whereIn('id', [$exhaust_credentials->id, $exhaust_reset->id])->update([
            'attempts' => MailError::MAX_ATTEMPTS,
        ]);
        $exhaust_exit = Artisan::call('outbox:send');
        $command_output .= Artisan::output();
        $seen_jobs .= secret_hunt_columns('jobs', ['payload']);
        $escaped = $escaped || secret_hunt_handle($exhaust_credentials->id) || secret_hunt_handle($exhaust_reset->id);

        $old_credentials = secret_hunt_queue(OutboundMessage::TEMPLATE_CREDENTIALS, $reissue_user, $password, $token);
        $old_reset = secret_hunt_queue(OutboundMessage::TEMPLATE_PASSWORD_RESET, $reissue_user, $password, $token);
        $new_credentials = secret_hunt_queue(OutboundMessage::TEMPLATE_CREDENTIALS, $reissue_user, $password, $token);
        $new_reset = secret_hunt_queue(OutboundMessage::TEMPLATE_PASSWORD_RESET, $reissue_user, $password, $token);
        $reissue_exit = Artisan::call('outbox:send');
        $command_output .= Artisan::output();
        $seen_jobs .= secret_hunt_columns('jobs', ['payload']);
        $escaped = $escaped
            || secret_hunt_handle($old_credentials->id)
            || secret_hunt_handle($old_reset->id)
            || secret_hunt_handle($new_credentials->id)
            || secret_hunt_handle($new_reset->id);

        $retry_credentials = secret_hunt_retry($exhaust_credentials, $admin);
        $retry_reset = secret_hunt_retry($exhaust_reset, $admin);

        $boom = secret_hunt_queue(OutboundMessage::TEMPLATE_CREDENTIALS, $boom_user, $password, $token);
        $boom_exit = Artisan::call('outbox:send');
        $command_output .= Artisan::output();
        $seen_jobs .= secret_hunt_columns('jobs', ['payload']);
        $transport_installed = secret_hunt_install_throwing_transport($password);
        $boom_escaped = secret_hunt_handle($boom->id);

        if (! $test instanceof TestCase) {
            $page = '';
            $http_status = 0;
            $http_body = '';
            $health = '';
        } else {
            $test->actingAs($admin);
            $http = $test->get('/staff/email-outbox');
            $http_status = $http->status();
            $http_body = (string) $http->getContent();

            try {
                $page = Livewire::test(EmailOutboxPage::class)->html();
            } catch (Throwable $exception) {
                $page = $exception->getMessage();
            }

            $health = (string) $test->get('/health')->getContent();
        }

        $haystack = implode("\n", [
            $logged,
            $command_output,
            $seen_jobs,
            secret_hunt_columns('jobs', ['payload']),
            secret_hunt_columns('failed_jobs', ['payload', 'exception']),
            secret_hunt_columns('audit_logs', ['action', 'entity', 'before', 'after', 'ip']),
            secret_hunt_outbox_text(),
            secret_hunt_columns('cache', ['key', 'value']),
            secret_hunt_columns('cache_locks', ['key', 'owner']),
            secret_hunt_columns('sessions', ['payload', 'ip_address', 'user_agent']),
            secret_hunt_columns('notifications', ['type', 'data']),
            $page,
            $http_body,
            $health,
            $retry_credentials['detail'],
            $retry_reset['detail'],
            secret_hunt_log_files(),
        ]);
        $leaked = secret_hunt_has($haystack, $password) || secret_hunt_has($haystack, $token);
        $page_rendered = secret_hunt_has($page, 'Email outbox') || secret_hunt_has($http_body, 'Email outbox');

        expect($sent_now)->toBeTrue()
            ->and($exit)->toBe(0)
            ->and($exhaust_exit)->toBe(0)
            ->and($reissue_exit)->toBe(0)
            ->and($boom_exit)->toBe(0)
            ->and($escaped)->toBeFalse()
            ->and($boom_escaped)->toBeFalse()
            ->and($transport_installed)->toBeTrue()
            ->and($retry_credentials['refused'])->toBeTrue()
            ->and($retry_reset['refused'])->toBeTrue()
            ->and(secret_hunt_was_sent($sent_credentials->id))->toBeTrue()
            ->and(secret_hunt_was_sent($sent_reset->id))->toBeTrue()
            ->and(secret_hunt_was_sent($new_credentials->id))->toBeTrue()
            ->and(secret_hunt_was_sent($new_reset->id))->toBeTrue()
            ->and(secret_hunt_failed_closed($exhaust_credentials->id, MailError::PROVIDER_UNAVAILABLE))->toBeTrue()
            ->and(secret_hunt_failed_closed($exhaust_reset->id, MailError::PROVIDER_UNAVAILABLE))->toBeTrue()
            ->and(secret_hunt_failed_closed($old_credentials->id, MailError::SUPERSEDED))->toBeTrue()
            ->and(secret_hunt_failed_closed($old_reset->id, MailError::SUPERSEDED))->toBeTrue()
            ->and(secret_hunt_failed_closed($boom->id, MailError::OUTBOX_SEND_FAILED))->toBeTrue()
            ->and($http_status)->toBe(200)
            ->and($page_rendered)->toBeTrue()
            ->and($leaked)->toBeFalse();
    });
});
