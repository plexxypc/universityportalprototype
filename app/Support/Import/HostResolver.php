<?php

declare(strict_types=1);

namespace App\Support\Import;

/**
 * Looks up the addresses for one host. The caller pins the address it accepts.
 */
interface HostResolver
{
    /**
     * Addresses in lookup order. An empty list means the host did not resolve.
     *
     * @return list<string>
     */
    public function addresses(string $host): array;
}
