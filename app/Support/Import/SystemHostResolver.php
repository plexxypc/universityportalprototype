<?php

declare(strict_types=1);

namespace App\Support\Import;

/**
 * DNS lookup used by a real Google Sheet download.
 *
 * The metadata hostname returns no addresses. An address literal is returned
 * unchanged so the caller can refuse it without a lookup.
 */
final class SystemHostResolver implements HostResolver
{
    /**
     * Addresses in lookup order.
     *
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        $host = strtolower(rtrim($host, '.'));

        if ($host === '' || $host === 'metadata.google.internal') {
            return [];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (! function_exists('dns_get_record')) {
            return [];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $addresses[] = $record['ip'];
            }

            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }
}
