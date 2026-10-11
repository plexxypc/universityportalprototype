<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MailService;
use Illuminate\Console\Command;

/**
 * Dispatch due outbox rows.
 *
 * The job is the only sender. This command prints counts only.
 */
final class SendOutbox extends Command
{
    protected $signature = 'outbox:send';

    protected $description = 'Dispatch due outbox rows. Prints counts only.';

    /**
     * Dispatch one batch and print the counts.
     */
    public function handle(MailService $mail): int
    {
        $batch = (int) config('mail.outbox_batch');
        $result = $mail->dispatchDue($batch > 0 ? $batch : 25);

        $this->line('dispatched='.$result->dispatched);
        $this->line('skipped='.$result->skipped);
        $this->line('exhausted='.$result->exhausted);
        $this->line('limit_reached='.($result->limit_reached ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
