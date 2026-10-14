<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Exceptions\ImportFetchException;

/**
 * Downloads a public Google Sheet as CSV.
 *
 * The export URL is built here. Each hop must be HTTPS on docs.google.com
 * or a host ending in .googleusercontent.com. The validated address is
 * pinned for that hop so a later lookup cannot change it.
 */
final class GoogleSheetClient
{
    public function __construct(
        private readonly HostResolver $resolver,
        private readonly PinnedHttp $http,
    ) {}

    /**
     * The real DNS resolver and the cURL client.
     */
    public static function make(): self
    {
        return new self(new SystemHostResolver, new CurlPinnedHttp);
    }

    /**
     * CSV text for a spreadsheet link the actor pasted.
     */
    public function fetch(string $link): string
    {
        SheetFetchLog::clear();

        return $this->fetchUrl(GoogleSheetLink::exportUrl($link), 0);
    }

    /**
     * Request one allow-listed URL, then follow a permitted redirect.
     */
    private function fetchUrl(string $url, int $redirects): string
    {
        SheetFetchLog::setHop($redirects + 1);
        $hop = $this->hop($url);
        $pinned = $this->pin($hop['host']);
        $response = $this->http->get(
            $url,
            $pinned,
            SpreadsheetLimits::CONNECT_TIMEOUT_SECONDS,
            SpreadsheetLimits::TOTAL_TIMEOUT_SECONDS,
            SpreadsheetLimits::MAX_BYTES,
        );

        if ($response->too_large || strlen($response->body) > SpreadsheetLimits::MAX_BYTES) {
            $this->fail('too_large', ImportFetchException::MESSAGE_SIZE);
        }

        if (in_array($response->status, [301, 302, 303, 307, 308], true)) {
            if ($redirects >= SpreadsheetLimits::MAX_REDIRECTS) {
                $this->fail('too_many_redirects');
            }

            return $this->fetchUrl($this->nextUrl($response->location), $redirects + 1);
        }

        if (in_array($response->status, [401, 403], true)) {
            $this->fail('status_'.$response->status, ImportFetchException::MESSAGE_PRIVATE);
        }

        if ($this->isPrivate($response)) {
            $this->fail('not_csv', ImportFetchException::MESSAGE_PRIVATE);
        }

        if ($response->status !== 200) {
            $this->fail('status_'.$response->status);
        }

        return $response->body;
    }

    /**
     * Scheme, port, and host checks for one request URL.
     *
     * @return array{host: string, port: int}
     */
    private function hop(string $url): array
    {
        if (strlen($url) > 2_000) {
            $this->fail('bad_url');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            $this->fail('bad_url');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            $this->fail('bad_url');
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $port = (int) ($parts['port'] ?? 443);

        if ($host === '' || $port !== 443) {
            $this->fail('bad_url');
        }

        if ($host === 'accounts.google.com' || str_ends_with($host, '.accounts.google.com')) {
            $this->fail('private_sheet', ImportFetchException::MESSAGE_PRIVATE);
        }

        if ($host === 'metadata.google.internal' || ! $this->hostAllowed($host)) {
            $this->fail('redirect_off_allowlist');
        }

        return ['host' => $host, 'port' => $port];
    }

    /**
     * docs.google.com, or a name that ends in .googleusercontent.com.
     */
    private function hostAllowed(string $host): bool
    {
        if ($host === 'docs.google.com') {
            return true;
        }

        return str_ends_with($host, '.googleusercontent.com');
    }

    /**
     * The first address, after every returned address has been accepted.
     */
    private function pin(string $host): string
    {
        $addresses = $this->resolver->addresses($host);

        if ($addresses === []) {
            $this->fail('dns');
        }

        $blocked = 0;

        foreach ($addresses as $address) {
            if (BlockedAddress::isBlocked($address)) {
                $blocked++;
            }
        }

        if ($blocked === count($addresses)) {
            $this->fail('no_public_address');
        }

        if ($blocked > 0) {
            $this->fail('blocked_address');
        }

        return $addresses[0];
    }

    /**
     * An absolute HTTPS location. Any other form is refused.
     */
    private function nextUrl(?string $location): string
    {
        $location = trim((string) $location);

        if (preg_match('#\Ahttps://#', $location) !== 1) {
            $this->fail('redirect_off_allowlist');
        }

        return $location;
    }

    /**
     * A login page or HTML body means the sheet is not shared for viewing.
     */
    private function isPrivate(PinnedHttpResponse $response): bool
    {
        if (str_contains(strtolower($response->content_type), 'text/html')) {
            return true;
        }

        return str_starts_with(ltrim($response->body), '<');
    }

    /**
     * Log one category and raise the fixed message for the wizard.
     */
    private function fail(string $category, string $message = ImportFetchException::MESSAGE_DOWNLOAD): never
    {
        SheetFetchLog::failed($category);

        throw new ImportFetchException($message);
    }
}
