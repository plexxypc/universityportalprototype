<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Shows that the scheduler ran recently. It does not show that the queue worker is consuming jobs.
Schedule::command('portal:heartbeat')->everyMinute();

// Dispatches due outbox rows. The job is the only sender.
Schedule::command('outbox:send')->everyMinute()->withoutOverlapping();

// Staged spreadsheets hold personal data. Drop files older than 24 hours.
Schedule::command('imports:prune-staged')->hourly()->withoutOverlapping();

// Re-check pending payments. One run stops when its time budget is spent.
Schedule::command('payments:poll-pending')->everyMinute()->withoutOverlapping();
