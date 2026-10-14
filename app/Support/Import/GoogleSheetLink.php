<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Exceptions\ImportFetchException;

/**
 * Turns a Google Sheets link into the CSV export URL this server builds.
 *
 * The pasted link is never requested.
 */
final class GoogleSheetLink
{
    /**
     * Export URL for a docs.google.com spreadsheet link.
     */
    public static function exportUrl(string $link): string
    {
        $link = trim($link);

        if ($link === '' || preg_match('/[\s@\\\\]/', $link) === 1) {
            throw new ImportFetchException(ImportFetchException::MESSAGE_LINK);
        }

        $parts = parse_url($link);

        if (! is_array($parts)) {
            throw new ImportFetchException(ImportFetchException::MESSAGE_LINK);
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $port = (int) ($parts['port'] ?? 443);
        $path = (string) ($parts['path'] ?? '');

        if (($parts['scheme'] ?? '') !== 'https' || $host !== 'docs.google.com' || $port !== 443) {
            throw new ImportFetchException(ImportFetchException::MESSAGE_LINK);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ImportFetchException(ImportFetchException::MESSAGE_LINK);
        }

        if (preg_match('#^/spreadsheets/d/([A-Za-z0-9_-]{10,128})(?:/|$)#', $path, $matches) !== 1) {
            throw new ImportFetchException(ImportFetchException::MESSAGE_LINK);
        }

        $query = [];
        $fragment = [];

        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        if (isset($parts['fragment'])) {
            parse_str((string) $parts['fragment'], $fragment);
        }

        $gid = self::gid($query) ?? self::gid($fragment) ?? '0';

        return 'https://docs.google.com/spreadsheets/d/'.$matches[1].'/export?format=csv&gid='.$gid;
    }

    /**
     * A numeric gid, null when the key is absent, or a refusal when it is invalid.
     *
     * @param  array<string, mixed>  $values
     */
    private static function gid(array $values): ?string
    {
        if (! array_key_exists('gid', $values)) {
            return null;
        }

        $gid = $values['gid'];

        if (is_array($gid)) {
            $gid = $gid[0] ?? null;
        }

        if (! is_string($gid) && ! is_int($gid)) {
            throw new ImportFetchException(ImportFetchException::MESSAGE_LINK);
        }

        $gid = (string) $gid;

        if (preg_match('/^\d{1,12}$/', $gid) !== 1) {
            throw new ImportFetchException(ImportFetchException::MESSAGE_LINK);
        }

        return $gid;
    }
}
