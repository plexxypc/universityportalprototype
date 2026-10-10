<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\MailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Send one outbox row.
 *
 * The payload is the outbox id. Failures are stored as a fixed code and are
 * not rethrown, so the worker retry count does not add attempts. This job
 * does not dispatch another send. Retries wait for `outbox:send` (TASK-049).
 */
class SendOutboxEmail implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $email_outbox_id) {}

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
