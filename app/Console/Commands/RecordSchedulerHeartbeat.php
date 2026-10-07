<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Write the current time so /health can see that the scheduler has run.
 *
 * A recent value shows that this command ran. It does not show that the
 * queue worker is consuming jobs.
 */
final class RecordSchedulerHeartbeat extends Command
{
    protected $signature = 'portal:heartbeat';

    protected $description = 'Record that the scheduler ran. This does not show that the queue worker is consuming jobs.';

    /**
     * Store the current timestamp for the health check.
     */
    public function handle(): int
    {
        $store_seconds = (int) config('portal.health.heartbeat_store_seconds');

        Cache::put(
            (string) config('portal.health.heartbeat_key'),
            now()->getTimestamp(),
            $store_seconds > 0 ? $store_seconds : 600,
        );

        $this->info('Scheduler heartbeat recorded.');

        return self::SUCCESS;
    }
}
