<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\MailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Send one outbox row.
 *
 * The payload is the outbox id. Failures are stored as a fixed code and are
 * not rethrown, so the worker retry count does not add attempts. This job
 * does not dispatch another send. Retries wait for `outbox:send`.
 * A second dispatch of the same outbox id is dropped while the first is queued.
 */
class SendOutboxEmail implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $email_outbox_id) {}

    /**
     * One queued job per outbox row.
     */
    public function uniqueId(): string
    {
        return (string) $this->email_outbox_id;
    }

    /**
     * Hold the uniqueness lock on the database cache store.
     */
    public function uniqueVia(): Repository
    {
        return Cache::store('database');
    }

    /**
     * Send the row. An unexpected error is stored without its message.
     */
    public function handle(MailService $mail): void
    {
        try {
            $mail->deliver($this->email_outbox_id);
        } catch (Throwable) {
            $mail->recordUnexpectedFailure($this->email_outbox_id);
        }
    }
}
