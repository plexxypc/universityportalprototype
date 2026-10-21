<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PaymentEventSource;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Re-check pending and expired payments until the time budget is spent.
 *
 * A row checked inside the verify interval is left alone. The command
 * prints nothing about the payments.
 */
final class PollPendingPayments extends Command
{
    protected $signature = 'payments:poll-pending';

    protected $description = 'Re-check pending payments within the time budget.';

    /**
     * Verify due payments until the budget runs out.
     */
    public function handle(PaymentService $payments): int
    {
        $budget = (int) config('payments.poll_budget_seconds');

        if ($budget < 1) {
            return self::SUCCESS;
        }

        $deadline = microtime(true) + $budget;
        $interval = max(1, (int) config('payments.verify_interval_seconds'));
        $cutoff = now()->subSeconds($interval);
        $rows = Payment::query()
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Expired->value])
            ->where(function (Builder $query) use ($cutoff): void {
                $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'reference']);

        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $payments->verifyAndSettle((string) $row->reference, PaymentEventSource::Poll->value);
        }

        return self::SUCCESS;
    }
}
