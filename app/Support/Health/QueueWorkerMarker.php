<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Support\Facades\Cache;

/**
 * Records that the queue worker looped.
 *
 * The scheduler heartbeat does not show this. /health reads the timestamp
 * from the same cache store. A missing or old value means the worker is stopped.
 */
final class QueueWorkerMarker
{
    /**
     * Store the current time, at most once per throttle window.
     */
    public function record(): void
    {
        $key = $this->key();
        $throttle_seconds = $this->positive(
            (int) config('portal.health.worker_throttle_seconds'),
            30,
        );
        $store_seconds = $this->positive(
            (int) config('portal.health.worker_store_seconds'),
            600,
        );
        $existing = Cache::get($key);

        if ($this->isTimestamp($existing) && (now()->getTimestamp() - (int) $existing) < $throttle_seconds) {
            return;
        }

        Cache::put($key, now()->getTimestamp(), $store_seconds);
    }

    /**
     * Cache key shared with /health.
     */
    private function key(): string
    {
        return (string) config('portal.health.worker_key');
    }

    /**
     * A positive configured number, or the fallback.
     */
    private function positive(int $value, int $fallback): int
    {
        return $value > 0 ? $value : $fallback;
    }

    /**
     * Whether the cached value is a unix time.
     */
    private function isTimestamp(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }
}
