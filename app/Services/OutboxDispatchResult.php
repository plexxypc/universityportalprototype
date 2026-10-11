<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Counts from one outbox:send run.
 *
 * The command prints these numbers only. Addresses and bodies are not included.
 */
final class OutboxDispatchResult
{
    public function __construct(
        public int $dispatched,
        public int $skipped,
        public int $exhausted,
        public bool $limit_reached,
    ) {}
}
