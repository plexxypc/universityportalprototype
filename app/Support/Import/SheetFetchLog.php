<?php

declare(strict_types=1);

namespace App\Support\Import;

use Throwable;

/**
 * One fixed line for a failed Google Sheet download.
 *
 * The line is a category, a hop number, and sometimes a cURL error number.
 * It never includes a link, a sheet id, a URL, a response body, or a cookie.
 */
final class SheetFetchLog
{
    /**
     * Categories that may be written. Anything else is recorded as unknown.
     *
     * @var list<string>
     */
    private const array CATEGORIES = [
        'dns',
        'no_public_address',
        'connect',
        'tls',
        'timeout',
        'redirect_off_allowlist',
        'too_many_redirects',
        'not_csv',
        'too_large',
        'blocked_address',
        'bad_url',
        'private_sheet',
        'curl',
        'unknown',
    ];

    private static int $hop = 1;

    private static string $last = '';

    /**
     * The hop about to be requested. The first request is hop 1.
     */
    public static function setHop(int $hop): void
    {
        self::$hop = max(0, $hop);
    }

    public static function hop(): int
    {
        return self::$hop;
    }

    /**
     * Forget the previous line before a new download.
     */
    public static function clear(): void
    {
        self::$last = '';
        self::$hop = 1;
    }

    /**
     * The last line written in this process, or an empty string.
     */
    public static function last(): string
    {
        return self::$last;
    }

    /**
     * Record one failure. A category outside the fixed list becomes unknown.
     */
    public static function failed(string $category, ?int $curl_errno = null): void
    {
        $line = 'sheet_fetch_failed category='.self::category($category).' hop='.self::$hop;

        if ($curl_errno !== null) {
            $line .= ' curl_errno='.max(0, $curl_errno);
        }

        self::$last = $line;
        self::write($line);
    }

    /**
     * Map a cURL error number onto a fixed category.
     */
    public static function curlCategory(int $errno): string
    {
        return match ($errno) {
            5, 6 => 'dns',
            7 => 'connect',
            28 => 'timeout',
            35, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91, 98 => 'tls',
            default => 'curl',
        };
    }

    /**
     * Keep only a known category, or status_ and a 3-digit code.
     */
    private static function category(string $category): string
    {
        if (preg_match('/\Astatus_(\d{3})\z/', $category, $matches) === 1) {
            return 'status_'.$matches[1];
        }

        if (in_array($category, self::CATEGORIES, true)) {
            return $category;
        }

        return 'unknown';
    }

    /**
     * Write the line when the application log is available.
     *
     * A logging failure must not replace the download error.
     */
    private static function write(string $line): void
    {
        try {
            if (function_exists('app') && app()->bound('log')) {
                app('log')->warning($line);
            }
        } catch (Throwable) {
            // The category is already stored for the probe command.
        }
    }
}
