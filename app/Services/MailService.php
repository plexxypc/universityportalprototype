<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EmailStatus;
use App\Jobs\SendOutboxEmail;
use App\Models\EmailOutbox;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mail\MailError;
use App\Support\Mail\MailTransport;
use App\Support\Mail\OutboundMessage;
use App\Support\Mail\ProviderStatus;
use App\Support\Mail\RenderedMail;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Throwable;

/**
 * Records outbox rows and sends one row through Laravel's mailer.
 *
 * The job carries only the outbox id. This service does not dispatch a
 * second job. `outbox:send` dispatches later retries. The job is the only sender.
 */
final class MailService
{
    public const string LOCK_PREFIX = 'outbox-send:';

    public const string CIRCUIT_STREAK_KEY = 'mail-send-failure-streak';

    public const string CIRCUIT_PAUSE_KEY = 'mail-send-paused';

    public const string DAILY_LIMIT_KEY = 'mail-daily-limit-reached';

    public const string TEST_THROTTLE_PREFIX = 'mail-test-send:';

    private const int LOCK_SECONDS = 30;

    private const int CIRCUIT_THRESHOLD = 3;

    private const int CIRCUIT_PAUSE_SECONDS = 900;

    /**
     * @var list<string>
     */
    private const array ALLOWED_MAILERS = ['log', 'array', 'brevo'];

    public function __construct(
        private readonly OutboundMessage $messages,
        private readonly ProviderStatus $provider_status,
        private readonly Audit $audit,
    ) {}

    /**
     * Queue one email and dispatch the send job after the transaction commits.
     *
     * Credential and reset payloads are stored encrypted. The stored body is a
     * fixed placeholder. Other templates store the escaped body and a null secret.
     *
     * @param  array<string, mixed>  $data
     */
    public function send(string $template, string $recipient, array $data, ?int $user_id = null): EmailOutbox
    {
        if (! $this->messages->isKnown($template)) {
            throw new InvalidArgumentException(MailError::UNKNOWN_TEMPLATE);
        }

        if (! $this->recipientIsValid($recipient)) {
            throw new InvalidArgumentException(MailError::INVALID_RECIPIENT);
        }

        $this->messages->assertSendable($template, $data);
        $subject = $this->messages->subject($template, $data);
        $secret = $this->messages->isSecretTemplate($template);
        $body_html = OutboundMessage::HELD_BODY;
        $body_text = OutboundMessage::HELD_BODY;

        if (! $secret) {
            $stored = $template === OutboundMessage::TEMPLATE_TEST
                ? $this->messages->storedBody($data)
                : $this->messages->renderPublic($template, $data);
            $body_html = $stored->html;
            $body_text = $stored->text;
        }

        return DB::transaction(function () use ($template, $recipient, $data, $user_id, $subject, $secret, $body_html, $body_text): EmailOutbox {
            if ($secret && $user_id !== null) {
                $this->supersedeQueued($user_id, $template);
            }

            $row = EmailOutbox::query()->create([
                'user_id' => $user_id,
                'recipient_email' => $recipient,
                'template' => $template,
                'subject' => $subject,
                'body_html' => $body_html,
                'body_text' => $body_text,
                'secrets' => $secret ? $this->messages->secretPayload($template, $data) : null,
                'status' => EmailStatus::Queued,
                'attempts' => 0,
                'last_error' => null,
                'sent_at' => null,
                'redacted_at' => null,
            ]);

            SendOutboxEmail::dispatch($row->id)->afterCommit();

            return $row;
        });
    }

    /**
     * Clear one row's secret and mark it failed.
     *
     * A sent row is left as it is. The secret was already cleared on send.
     */
    public function supersede(EmailOutbox $row): void
    {
        DB::transaction(function () use ($row): void {
            $locked = EmailOutbox::query()->lockForUpdate()->find($row->id);

            if (! $locked instanceof EmailOutbox || $locked->status === EmailStatus::Sent) {
                return;
            }

            $this->markFailed($locked, MailError::SUPERSEDED, true);
        });
    }

    /**
     * Send one queued row, or record a fixed error and return.
     *
     * The database lock is held across the provider call and the status update.
     * This method does not dispatch another job.
     */
    public function deliver(int $email_outbox_id): void
    {
        $cache = Cache::store('database');

        if (! $cache instanceof Repository) {
            return;
        }

        $store = $cache->getStore();

        if (! $store instanceof LockProvider) {
            return;
        }

        $lock = $store->lock(self::LOCK_PREFIX.$email_outbox_id, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->deliverLocked($email_outbox_id);
        } finally {
            $lock->release();
        }
    }

    /**
     * Dispatch due queued rows, and fail rows that have used every attempt.
     *
     * This method does not call the mailer. A row whose send lock is already
     * held is skipped. At the daily cap, further rows stay queued.
     */
    public function dispatchDue(int $batch_size): OutboxDispatchResult
    {
        $batch_size = max(1, $batch_size);
        $paused = $this->circuitIsPaused();
        $cap = $this->dailyCap();
        $sent = $cap === null ? 0 : $this->sentToday();
        $dispatched = 0;
        $skipped = 0;
        $exhausted = 0;
        $limit_reached = false;

        EmailOutbox::query()
            ->where('status', EmailStatus::Queued)
            ->where('attempts', '>=', MailError::MAX_ATTEMPTS)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$exhausted, &$skipped): bool {
                foreach ($rows as $row) {
                    $this->deliver($row->id);
                    $row->refresh();

                    if ($row->status === EmailStatus::Failed) {
                        $exhausted++;
                    } else {
                        $skipped++;
                    }
                }

                return true;
            });

        if (! $paused) {
            EmailOutbox::query()
                ->where('status', EmailStatus::Queued)
                ->where('attempts', '<', MailError::MAX_ATTEMPTS)
                ->orderBy('id')
                ->chunkById(100, function ($rows) use (
                    &$dispatched,
                    &$skipped,
                    &$limit_reached,
                    $batch_size,
                    $cap,
                    $sent,
                ): bool {
                    foreach ($rows as $row) {
                        if ($dispatched >= $batch_size) {
                            return false;
                        }

                        if (! $this->isDue($row)) {
                            continue;
                        }

                        if ($cap !== null && ($sent + $dispatched) >= $cap) {
                            $limit_reached = true;

                            return false;
                        }

                        if (! $this->dispatchOne($row->id)) {
                            $skipped++;

                            continue;
                        }

                        $dispatched++;
                    }

                    return true;
                });
        }

        if ($cap !== null && ($sent + $dispatched) >= $cap) {
            $this->rememberDailyLimit();
            $limit_reached = true;
        } else {
            Cache::store('database')->forget(self::DAILY_LIMIT_KEY);
        }

        return new OutboxDispatchResult($dispatched, $skipped, $exhausted, $limit_reached);
    }

    /**
     * Whether the staff banner should say the daily cap was reached.
     */
    public function dailyLimitReached(): bool
    {
        return Cache::store('database')->get(self::DAILY_LIMIT_KEY) === true;
    }

    /**
     * Whether the circuit breaker is holding mail.
     */
    public function sendingIsPaused(): bool
    {
        return $this->circuitIsPaused();
    }

    /**
     * Queue a failed row again.
     *
     * Credentials and password reset cannot be retried. The secret is already
     * gone, and the page tells the operator to re-issue or request a new link.
     */
    public function retry(EmailOutbox $row, User $actor, ?string $ip): void
    {
        $this->assertCanManage($actor);

        DB::transaction(function () use ($row, $actor, $ip): void {
            $locked = EmailOutbox::query()->lockForUpdate()->find($row->id);

            if (! $locked instanceof EmailOutbox
                || $locked->status !== EmailStatus::Failed
                || $this->messages->isSecretTemplate($locked->template)) {
                throw new AuthorizationException;
            }

            $before = [
                'status' => $locked->status->value,
                'template' => $locked->template,
            ];
            $locked->status = EmailStatus::Queued;
            $locked->attempts = 0;
            $locked->last_error = null;
            $locked->sent_at = null;
            $locked->save();

            $this->audit->record(
                $actor->id,
                AuditService::ACTION_RETRIED,
                AuditService::ENTITY_EMAIL_OUTBOX,
                $locked->id,
                $before,
                [
                    'status' => EmailStatus::Queued->value,
                    'template' => $locked->template,
                ],
                $ip,
            );

            SendOutboxEmail::dispatch($locked->id)->afterCommit();
        });
    }

    /**
     * Dispatch a queued row now, including credentials and password reset.
     *
     * The rendered body is not returned. Backoff is skipped for this call.
     * The daily cap and the circuit breaker still apply.
     */
    public function sendNow(EmailOutbox $row, User $actor, ?string $ip): bool
    {
        $this->assertCanManage($actor);

        return DB::transaction(function () use ($row, $actor, $ip): bool {
            $locked = EmailOutbox::query()->lockForUpdate()->find($row->id);

            if (! $locked instanceof EmailOutbox
                || $locked->status !== EmailStatus::Queued
                || $locked->attempts >= MailError::MAX_ATTEMPTS) {
                throw new AuthorizationException;
            }

            if ($this->circuitIsPaused() || $this->dailyCapReached()) {
                if ($this->dailyCapReached()) {
                    $this->rememberDailyLimit();
                }

                return false;
            }

            $before = [
                'status' => $locked->status->value,
                'template' => $locked->template,
            ];

            if (! $this->isDue($locked)) {
                $locked->timestamps = false;
                $locked->updated_at = now()->subHours(2);
                $locked->save();
            }

            $this->audit->record(
                $actor->id,
                AuditService::ACTION_SENT_NOW,
                AuditService::ENTITY_EMAIL_OUTBOX,
                $locked->id,
                $before,
                [
                    'status' => EmailStatus::Queued->value,
                    'template' => $locked->template,
                ],
                $ip,
            );

            SendOutboxEmail::dispatch($locked->id)->afterCommit();

            return true;
        });
    }

    /**
     * Queue a test email to the signed-in admin only.
     *
     * One send per 60 seconds. A submitted address is ignored by the caller.
     */
    public function sendTest(User $actor, ?string $ip): bool
    {
        $this->assertCanManage($actor);

        $throttle_key = self::TEST_THROTTLE_PREFIX.$actor->id;

        if (! Cache::store('database')->add($throttle_key, 1, 60)) {
            return false;
        }

        try {
            DB::transaction(function () use ($actor, $ip): void {
                $row = $this->send(
                    OutboundMessage::TEMPLATE_TEST,
                    $actor->email,
                    ['message' => 'This is a test from the portal.'],
                    $actor->id,
                );

                $this->audit->record(
                    $actor->id,
                    AuditService::ACTION_TEST_QUEUED,
                    AuditService::ENTITY_EMAIL_OUTBOX,
                    $row->id,
                    null,
                    [
                        'template' => OutboundMessage::TEMPLATE_TEST,
                        'status' => EmailStatus::Queued->value,
                    ],
                    $ip,
                );
            });
        } catch (Throwable $exception) {
            Cache::store('database')->forget($throttle_key);

            throw $exception;
        }

        return true;
    }

    /**
     * Refuse anyone who is not an Active Super Admin.
     */
    private function assertCanManage(User $actor): void
    {
        if (! $actor->can('email_outbox.manage')) {
            throw new AuthorizationException;
        }
    }

    /**
     * Record an unexpected failure without the exception text.
     */
    public function recordUnexpectedFailure(int $email_outbox_id): void
    {
        try {
            $row = EmailOutbox::query()->find($email_outbox_id);

            if (! $row instanceof EmailOutbox || $row->status !== EmailStatus::Queued) {
                return;
            }

            $this->markFailed($row, MailError::OUTBOX_SEND_FAILED, true);
        } catch (Throwable) {
            // The fixed code is the only thing this path may record.
        }
    }

    /**
     * Send the locked row or leave it for a later scheduler run.
     */
    private function deliverLocked(int $email_outbox_id): void
    {
        $row = EmailOutbox::query()->find($email_outbox_id);

        if (! $row instanceof EmailOutbox || $row->status !== EmailStatus::Queued) {
            return;
        }

        if ($row->attempts >= MailError::MAX_ATTEMPTS) {
            $this->markFailed($row, MailError::PROVIDER_UNAVAILABLE, true);

            return;
        }

        if ($this->circuitIsPaused()) {
            return;
        }

        if (! $this->isDue($row)) {
            return;
        }

        if ($this->dailyCapReached()) {
            $this->rememberDailyLimit();

            return;
        }

        $payload = $this->readSecrets($row);

        if ($payload === false) {
            return;
        }

        $mailer = (string) config('mail.default');

        if ($this->logMailerRefuses($mailer, $row->template)) {
            $this->markFailed($row, MailError::SECRET_TEMPLATE_REFUSED, true);

            return;
        }

        if (! in_array($mailer, self::ALLOWED_MAILERS, true)) {
            $this->markFailed($row, MailError::MAILER_REJECTED, true);

            return;
        }

        if ($mailer === 'brevo' && ! $this->brevoCanSend()) {
            $this->keepQueued($row, MailError::MAIL_UNCONFIGURED);

            return;
        }

        $rendered = $this->renderForSend($row, $payload);

        if ($rendered === null) {
            $this->markFailed($row, MailError::OUTBOX_SEND_FAILED, true);

            return;
        }

        $status_code = $this->handToMailer($mailer, $row, $rendered);

        if ($mailer === 'brevo') {
            if ($status_code === null) {
                $this->markFailed($row, MailError::OUTBOX_SEND_FAILED, true);

                return;
            }

            $this->applyProviderStatus($row, $status_code);

            return;
        }

        $this->markSent($row);
    }

    /**
     * Read the encrypted payload. Null means the row has no secret.
     * False means the ciphertext cannot be read and the row was failed.
     *
     * @return array<string, mixed>|null|false
     */
    private function readSecrets(EmailOutbox $row): array|null|false
    {
        $raw = $row->getRawOriginal('secrets');

        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw)) {
            $this->markUnreadable($row);

            return false;
        }

        $decoded = $this->decodeSecret(app(StringEncrypter::class), $raw);

        if ($decoded === null) {
            $this->markUnreadable($row);

            return false;
        }

        return $decoded;
    }

    /**
     * Decrypt one payload. A bad ciphertext returns null and is not logged.
     *
     * @return array<string, mixed>|null
     */
    private function decodeSecret(StringEncrypter $encrypter, string $payload): ?array
    {
        try {
            $json = $encrypter->decryptString($payload);
        } catch (DecryptException) {
            return null;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function renderForSend(EmailOutbox $row, ?array $payload): ?RenderedMail
    {
        if ($this->messages->isSecretTemplate($row->template)) {
            return $this->messages->renderSecret($row->template, $payload ?? []);
        }

        return new RenderedMail($row->body_html, $row->body_text);
    }

    /**
     * Hand the in-memory message to Laravel's mailer.
     *
     * Log and array transports report no HTTP status. Brevo reports a status code.
     */
    private function handToMailer(string $mailer, EmailOutbox $row, RenderedMail $rendered): ?int
    {
        $laravel_mailer = Mail::mailer($mailer);
        $transport = $laravel_mailer->getSymfonyTransport();

        $laravel_mailer->send(
            [
                'html' => new HtmlString($rendered->html),
                'raw' => $rendered->text,
            ],
            [],
            function (Message $message) use ($row): void {
                $message->to($row->recipient_email)->subject($row->subject);
            },
        );

        if ($transport instanceof MailTransport) {
            return $transport->statusCode();
        }

        return null;
    }

    /**
     * Apply a provider status without storing the response body.
     */
    private function applyProviderStatus(EmailOutbox $row, int $status_code): void
    {
        $classified = $this->provider_status->classify($status_code);

        if ($classified['kind'] === ProviderStatus::KIND_SUCCESS) {
            $this->markSent($row);

            return;
        }

        $error = $classified['error'] ?? MailError::OUTBOX_SEND_FAILED;

        if ($classified['kind'] === ProviderStatus::KIND_PERMANENT) {
            $this->markFailed($row, $error, true);

            return;
        }

        if ($classified['kind'] === ProviderStatus::KIND_AUTH) {
            $this->keepQueued($row, $error);
            $this->recordAuthFailure();

            return;
        }

        if ($classified['kind'] === ProviderStatus::KIND_QUOTA) {
            $this->keepQueued($row, $error);

            return;
        }

        $this->recordTemporaryFailure($row, $error);
    }

    /**
     * Mark the row sent. Secret templates are redacted in the same update.
     */
    private function markSent(EmailOutbox $row): void
    {
        $secret = $this->messages->isSecretTemplate($row->template);
        $row->status = EmailStatus::Sent;
        $row->sent_at = now();
        $row->last_error = null;
        $row->secrets = null;

        if ($secret) {
            $row->body_html = OutboundMessage::REDACTED_BODY;
            $row->body_text = OutboundMessage::REDACTED_BODY;
            $row->redacted_at = now();
        }

        $row->save();
        $this->clearAuthStreak();
    }

    /**
     * Fail the row. Secret templates drop the encrypted payload.
     */
    private function markFailed(EmailOutbox $row, string $error, bool $clear_secret): void
    {
        $row->status = EmailStatus::Failed;
        $row->last_error = MailError::clip($error);
        $row->redacted_at = null;
        $row->sent_at = null;

        if ($clear_secret) {
            $row->secrets = null;

            if ($this->messages->isSecretTemplate($row->template)) {
                $row->body_html = OutboundMessage::HELD_BODY;
                $row->body_text = OutboundMessage::HELD_BODY;
            }
        }

        $row->save();
    }

    /**
     * Leave the row queued. Attempts and the secret stay as they are.
     */
    private function keepQueued(EmailOutbox $row, string $error): void
    {
        $row->status = EmailStatus::Queued;
        $row->last_error = MailError::clip($error);
        $row->save();
    }

    /**
     * Count one temporary failure. The fifth failure clears the secret.
     */
    private function recordTemporaryFailure(EmailOutbox $row, string $error): void
    {
        $row->attempts = $row->attempts + 1;
        $row->last_error = MailError::clip($error);

        if ($row->attempts >= MailError::MAX_ATTEMPTS) {
            $row->status = EmailStatus::Failed;
            $row->secrets = null;
            $row->redacted_at = null;

            if ($this->messages->isSecretTemplate($row->template)) {
                $row->body_html = OutboundMessage::HELD_BODY;
                $row->body_text = OutboundMessage::HELD_BODY;
            }
        } else {
            $row->status = EmailStatus::Queued;
        }

        $row->save();
    }

    /**
     * Drop an unreadable ciphertext without copying it into the error.
     */
    private function markUnreadable(EmailOutbox $row): void
    {
        DB::table('email_outbox')->where('id', $row->id)->update([
            'status' => EmailStatus::Failed->value,
            'secrets' => null,
            'last_error' => MailError::SECRET_UNREADABLE,
            'redacted_at' => null,
            'body_html' => OutboundMessage::HELD_BODY,
            'body_text' => OutboundMessage::HELD_BODY,
            'updated_at' => now(),
        ]);
    }

    /**
     * Supersede queued secret mail for this account and template.
     */
    private function supersedeQueued(int $user_id, string $template): void
    {
        $rows = EmailOutbox::query()
            ->where('user_id', $user_id)
            ->where('template', $template)
            ->where('status', EmailStatus::Queued)
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            $this->markFailed($row, MailError::SUPERSEDED, true);
        }
    }

    private function recipientIsValid(string $recipient): bool
    {
        if ($recipient === '' || preg_match('/[\s\x00]/', $recipient) === 1) {
            return false;
        }

        return filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function logMailerRefuses(string $mailer, string $template): bool
    {
        return app()->environment('production')
            && $mailer === 'log'
            && $this->messages->isSecretTemplate($template);
    }

    /**
     * Production Brevo sends need an HTTPS host, a key, and a daily-limit setting.
     * Tests and the log mailer do not.
     */
    private function brevoCanSend(): bool
    {
        $url = (string) config('mail.mailers.brevo.api_url');
        $key = (string) config('mail.mailers.brevo.key');
        $parts = parse_url($url);
        $https = is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') !== '';

        if (! $https || $key === '') {
            return false;
        }

        if (! app()->environment('production')) {
            return true;
        }

        $limit = config('mail.daily_limit');

        return $limit !== null && $limit !== '';
    }

    /**
     * Dispatch one row when its send lock is free.
     *
     * The lock is released before the job is queued so the worker can hold it
     * across the provider call. ShouldBeUnique drops a second dispatch.
     */
    private function dispatchOne(int $email_outbox_id): bool
    {
        $cache = Cache::store('database');

        if (! $cache instanceof Repository) {
            return false;
        }

        $store = $cache->getStore();

        if (! $store instanceof LockProvider) {
            return false;
        }

        $lock = $store->lock(self::LOCK_PREFIX.$email_outbox_id, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return false;
        }

        $lock->release();
        SendOutboxEmail::dispatch($email_outbox_id);

        return true;
    }

    /**
     * Sent rows whose sent_at falls on the current Africa/Lagos day.
     */
    private function sentToday(): int
    {
        $start = now()->startOfDay()->format('Y-m-d H:i:s');
        $end = now()->endOfDay()->format('Y-m-d H:i:s');

        return EmailOutbox::query()
            ->where('status', EmailStatus::Sent)
            ->whereBetween('sent_at', [$start, $end])
            ->count();
    }

    /**
     * Daily cap from configuration. Null means the cap is off.
     */
    private function dailyCap(): ?int
    {
        $limit = config('mail.daily_limit');

        if ($limit === null || $limit === '' || ! is_numeric($limit)) {
            return null;
        }

        return max(0, (int) $limit);
    }

    /**
     * Whether today's sent rows have already filled the cap.
     */
    private function dailyCapReached(): bool
    {
        $cap = $this->dailyCap();

        return $cap !== null && $this->sentToday() >= $cap;
    }

    /**
     * Remember that the cap was reached until the end of the Lagos day.
     */
    private function rememberDailyLimit(): void
    {
        $seconds = (int) now()->diffInSeconds(now()->endOfDay());

        Cache::store('database')->put(self::DAILY_LIMIT_KEY, true, max($seconds, 1));
    }

    private function isDue(EmailOutbox $row): bool
    {
        $wait_seconds = match ($row->attempts) {
            0 => 0,
            1 => 60,
            2 => 5 * 60,
            3 => 15 * 60,
            4 => 60 * 60,
            default => null,
        };

        if ($wait_seconds === null) {
            return false;
        }

        if ($wait_seconds === 0) {
            return true;
        }

        $updated_at = $row->updated_at;

        if ($updated_at === null) {
            return false;
        }

        return $updated_at->copy()->addSeconds($wait_seconds)->lte(now());
    }

    private function circuitIsPaused(): bool
    {
        return Cache::store('database')->get(self::CIRCUIT_PAUSE_KEY) === true;
    }

    /**
     * Count HTTP 401 and 403 only. The third consecutive failure pauses sending.
     */
    private function recordAuthFailure(): void
    {
        $store = Cache::store('database');
        $streak = (int) $store->get(self::CIRCUIT_STREAK_KEY, 0) + 1;
        $store->forever(self::CIRCUIT_STREAK_KEY, $streak);

        if ($streak < self::CIRCUIT_THRESHOLD) {
            return;
        }

        $store->put(self::CIRCUIT_PAUSE_KEY, true, self::CIRCUIT_PAUSE_SECONDS);
        $store->forget(self::CIRCUIT_STREAK_KEY);
    }

    private function clearAuthStreak(): void
    {
        Cache::store('database')->forget(self::CIRCUIT_STREAK_KEY);
    }
}
