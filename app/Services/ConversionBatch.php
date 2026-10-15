<?php

declare(strict_types=1);

namespace App\Services;

/**
 * How many applicants a bulk conversion created, skipped, or refused.
 *
 * Failure text is a friendly message. It does not include a password.
 */
final readonly class ConversionBatch
{
    /**
     * @param  list<string>  $failures
     */
    public function __construct(
        public int $converted,
        public int $skipped,
        public array $failures,
    ) {}
}
