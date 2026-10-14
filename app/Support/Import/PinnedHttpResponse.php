<?php

declare(strict_types=1);

namespace App\Support\Import;

/**
 * One HTTP response from a connection that used a pinned address.
 */
final readonly class PinnedHttpResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public ?string $location,
        public string $content_type,
        public bool $too_large,
    ) {}
}
