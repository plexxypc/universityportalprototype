<?php

declare(strict_types=1);

namespace App\Support\Import;

/**
 * Fetches one HTTPS URL without resolving the host again.
 */
interface PinnedHttp
{
    /**
     * Download the URL through the already validated address.
     */
    public function get(
        string $url,
        string $pinned_ip,
        int $connect_timeout,
        int $timeout,
        int $max_bytes,
    ): PinnedHttpResponse;
}
