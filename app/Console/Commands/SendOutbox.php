<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MailService;
use App\Support\Console\StderrLine;
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
     * Dispatch one batch and write the counts to stderr.
     */
    public function handle(MailService $mail, StderrLine $stderr): int
    {
        $batch = (int) config('mail.outbox_batch');
        $result = $mail->dispatchDue($batch > 0 ? $batch : 25);

        $stderr->write('dispatched='.$result->dispatched);
        $stderr->write('skipped='.$result->skipped);
        $stderr->write('exhausted='.$result->exhausted);
        $stderr->write('limit_reached='.($result->limit_reached ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
