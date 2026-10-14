<?php

declare(strict_types=1);

namespace App\Support\Import;

/**
 * Addresses a Google Sheet download must not connect to.
 *
 * IPv4: 0.0.0.0/8, 10.0.0.0/8, 100.64.0.0/10, 127.0.0.0/8,
 * 169.254.0.0/16, 172.16.0.0/12, 192.0.0.0/24, 192.0.2.0/24,
 * 192.168.0.0/16, 198.18.0.0/15, 198.51.100.0/24, 203.0.113.0/24,
 * 224.0.0.0/4, and 240.0.0.0/4.
 * IPv6: unspecified, loopback, unique-local (fc00::/7), link-local
 * (fe80::/10), and multicast. IPv4-mapped IPv6 is judged as IPv4.
 * An unparseable value is blocked.
 */
final class BlockedAddress
{
    /**
     * Whether this address is unsafe to connect to.
     */
    public static function isBlocked(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::ipv4Blocked($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::ipv6Blocked($ip);
        }

        return true;
    }

    /**
     * Whether an IPv4 address is in a refused range.
     */
    private static function ipv4Blocked(string $ip): bool
    {
        $ranges = [
            ['0.0.0.0', 8],
            ['10.0.0.0', 8],
            ['100.64.0.0', 10],
            ['127.0.0.0', 8],
            ['169.254.0.0', 16],
            ['172.16.0.0', 12],
            ['192.0.0.0', 24],
            ['192.0.2.0', 24],
            ['192.168.0.0', 16],
            ['198.18.0.0', 15],
            ['198.51.100.0', 24],
            ['203.0.113.0', 24],
            ['224.0.0.0', 4],
            ['240.0.0.0', 4],
        ];

        foreach ($ranges as [$network, $bits]) {
            if (self::inRange($ip, $network, $bits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an IPv6 address, or the IPv4 inside a mapped address, is refused.
     */
    private static function ipv6Blocked(string $ip): bool
    {
        $packed = inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return true;
        }

        $mapped_prefix = str_repeat("\0", 10)."\xff\xff";

        if (str_starts_with($packed, $mapped_prefix)) {
            $mapped = inet_ntop(substr($packed, 12));

            return $mapped === false || self::ipv4Blocked($mapped);
        }

        $unspecified = inet_pton('::');
        $loopback = inet_pton('::1');

        if ($packed === $unspecified || $packed === $loopback) {
            return true;
        }

        $first = ord($packed[0]);
        $second = ord($packed[1]);

        if (($first & 0xFE) === 0xFC) {
            return true;
        }

        if ($first === 0xFE && ($second & 0xC0) === 0x80) {
            return true;
        }

        return $first === 0xFF;
    }

    /**
     * Whether the address falls inside a network of the given prefix length.
     */
    private static function inRange(string $ip, string $network, int $bits): bool
    {
        $ip_bytes = inet_pton($ip);
        $network_bytes = inet_pton($network);

        if ($ip_bytes === false || $network_bytes === false || strlen($ip_bytes) !== strlen($network_bytes)) {
            return false;
        }

        $full_bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($full_bytes > 0 && substr($ip_bytes, 0, $full_bytes) !== substr($network_bytes, 0, $full_bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ip_bytes[$full_bytes]) & $mask) === (ord($network_bytes[$full_bytes]) & $mask);
    }
}
