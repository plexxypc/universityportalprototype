<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Exceptions\ImportFetchException;
use CurlHandle;

/**
 * cURL download that connects to a pinned address.
 *
 * CURLOPT_RESOLVE forces the connection onto that address. Redirects are
 * not followed here; the caller checks each hop and pins it separately.
 */
final class CurlPinnedHttp implements PinnedHttp
{
    /**
     * The CURLOPT_RESOLVE entry for one host, port, and address.
     *
     * @return list<string>
     */
    public static function resolveOption(string $host, int $port, string $ip): array
    {
        $address = str_contains($ip, ':') ? '['.$ip.']' : $ip;

        return [$host.':'.$port.':'.$address];
    }

    /**
     * Download the URL through the pinned address, or refuse a blocked one.
     */
    public function get(
        string $url,
        string $pinned_ip,
        int $connect_timeout,
        int $timeout,
        int $max_bytes,
    ): PinnedHttpResponse {
        if (BlockedAddress::isBlocked($pinned_ip)) {
            SheetFetchLog::failed('blocked_address');

            throw new ImportFetchException(ImportFetchException::MESSAGE_DOWNLOAD);
        }

        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower(rtrim((string) ($parts['host'] ?? ''), '.')) : '';
        $port = is_array($parts) ? (int) ($parts['port'] ?? 443) : 0;

        if ($host === '' || ($parts['scheme'] ?? '') !== 'https' || $port !== 443) {
            SheetFetchLog::failed('bad_url');

            throw new ImportFetchException(ImportFetchException::MESSAGE_DOWNLOAD);
        }

        $body = '';
        $received = 0;
        $too_large = false;
        $header_bytes = 0;
        /** @var list<string> $header_lines */
        $header_lines = [];

        $handle = curl_init($url);

        if (! $handle instanceof CurlHandle) {
            SheetFetchLog::failed('connect');

            throw new ImportFetchException(ImportFetchException::MESSAGE_DOWNLOAD);
        }

        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $connect_timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_RESOLVE => self::resolveOption($host, $port, $pinned_ip),
            CURLOPT_IPRESOLVE => str_contains($pinned_ip, ':') ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: text/csv'],
            CURLOPT_WRITEFUNCTION => function (CurlHandle $curl, string $chunk) use (&$body, &$received, &$too_large, $max_bytes): int {
                unset($curl);
                $received += strlen($chunk);

                if ($received > $max_bytes) {
                    $too_large = true;

                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => function (CurlHandle $curl, string $line) use (&$header_lines, &$header_bytes): int {
                unset($curl);
                $header_bytes += strlen($line);

                if ($header_bytes > 65_536) {
                    return 0;
                }

                $header_lines[] = $line;

                return strlen($line);
            },
        ];

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https';
        } elseif (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        curl_setopt_array($handle, $options);
        $completed = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($too_large) {
            SheetFetchLog::failed('too_large');

            throw new ImportFetchException(ImportFetchException::MESSAGE_SIZE);
        }

        if ($completed !== true) {
            $errno = curl_errno($handle);
            $category = $header_bytes > 65_536 ? 'too_large' : SheetFetchLog::curlCategory($errno);
            SheetFetchLog::failed($category, $errno);

            throw new ImportFetchException(ImportFetchException::MESSAGE_DOWNLOAD);
        }

        return new PinnedHttpResponse(
            $status,
            $body,
            $this->headerValue($header_lines, 'Location'),
            $this->headerValue($header_lines, 'Content-Type') ?? '',
            false,
        );
    }

    /**
     * The last value of one response header.
     *
     * @param  list<string>  $lines
     */
    private function headerValue(array $lines, string $name): ?string
    {
        $prefix = strtolower($name).':';
        $value = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (str_starts_with(strtolower($trimmed), $prefix)) {
                $value = trim(substr($trimmed, strlen($name) + 1));
            }
        }

        return $value;
    }
}
