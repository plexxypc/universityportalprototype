<?php

declare(strict_types=1);

use App\Exceptions\ImportFetchException;
use App\Support\Import\BlockedAddress;
use App\Support\Import\CurlPinnedHttp;
use App\Support\Import\GoogleSheetClient;
use App\Support\Import\GoogleSheetLink;
use App\Support\Import\HostResolver;
use App\Support\Import\PinnedHttp;
use App\Support\Import\PinnedHttpResponse;
use App\Support\Import\SpreadsheetLimits;
use App\Support\Import\SpreadsheetReader;
use App\Support\Import\SystemHostResolver;

/**
 * @param  array<string, list<list<string>>>  $queues
 */
function google_sheet_resolver(array $queues): HostResolver
{
    return new class($queues) implements HostResolver
    {
        public int $calls = 0;

        /** @var list<string> */
        public array $hosts = [];

        /**
         * @param  array<string, list<list<string>>>  $queues
         */
        public function __construct(private array $queues) {}

        /**
         * @return list<string>
         */
        public function addresses(string $host): array
        {
            $this->calls++;
            $this->hosts[] = $host;
            $next = $this->queues[$host][0] ?? null;

            if (is_array($next)) {
                array_shift($this->queues[$host]);

                return $next;
            }

            return [];
        }

        /**
         * @return list<list<string>>
         */
        public function remaining(string $host): array
        {
            return $this->queues[$host] ?? [];
        }
    };
}

/**
 * @param  list<PinnedHttpResponse>  $responses
 */
function google_sheet_http(array $responses): PinnedHttp
{
    return new class($responses) implements PinnedHttp
    {
        /** @var list<array{url: string, ip: string, connect_timeout: int, timeout: int, max_bytes: int}> */
        public array $calls = [];

        /**
         * @param  list<PinnedHttpResponse>  $responses
         */
        public function __construct(private array $responses) {}

        public function get(
            string $url,
            string $pinned_ip,
            int $connect_timeout,
            int $timeout,
            int $max_bytes,
        ): PinnedHttpResponse {
            $this->calls[] = [
                'url' => $url,
                'ip' => $pinned_ip,
                'connect_timeout' => $connect_timeout,
                'timeout' => $timeout,
                'max_bytes' => $max_bytes,
            ];
            $next = array_shift($this->responses);

            if (! $next instanceof PinnedHttpResponse) {
                throw new RuntimeException('No scripted response.');
            }

            return $next;
        }
    };
}

function google_sheet_link(): string
{
    return 'https://docs.google.com/spreadsheets/d/abcdefghijklmnopqrstuv/edit?usp=sharing&gid=7#gid=9';
}

function google_sheet_export(): string
{
    return 'https://docs.google.com/spreadsheets/d/abcdefghijklmnopqrstuv/export?format=csv&gid=7';
}

it('builds the export url and ignores the pasted query except gid', function () {
    expect(GoogleSheetLink::exportUrl(google_sheet_link()))->toBe(google_sheet_export());
    expect(GoogleSheetLink::exportUrl('https://docs.google.com/spreadsheets/d/abcdefghijklmnopqrstuv/edit#gid=4'))
        ->toBe('https://docs.google.com/spreadsheets/d/abcdefghijklmnopqrstuv/export?format=csv&gid=4');
});

it('refuses links that are not a public docs spreadsheet url', function (string $link) {
    expect(fn () => GoogleSheetLink::exportUrl($link))
        ->toThrow(ImportFetchException::class, ImportFetchException::MESSAGE_LINK);
})->with([
    'http://docs.google.com/spreadsheets/d/abcdefghijklmnopqrstuv',
    'https://sheets.google.com/spreadsheets/d/abcdefghijklmnopqrstuv',
    'https://docs.google.com.evil.com/spreadsheets/d/abcdefghijklmnopqrstuv',
    'https://user:pass@docs.google.com/spreadsheets/d/abcdefghijklmnopqrstuv',
    'https://docs.google.com/document/d/abcdefghijklmnopqrstuv',
    'https://docs.google.com/spreadsheets/d/short',
    'https://169.254.169.254/spreadsheets/d/abcdefghijklmnopqrstuv',
    'https://docs.google.com/spreadsheets/d/abcdefghijklmnopqrstuv?gid=nope',
    '',
]);

it('pins the first address when a second lookup would be private', function () {
    $resolver = google_sheet_resolver([
        'docs.google.com' => [
            ['8.8.8.8'],
            ['127.0.0.1'],
        ],
    ]);
    $http = google_sheet_http([
        new PinnedHttpResponse(200, "first_name\nAda\n", null, 'text/csv', false),
    ]);
    $body = (new GoogleSheetClient($resolver, $http))->fetch(google_sheet_link());
    $sheet = (new SpreadsheetReader)->readCsv($body);

    expect($resolver->calls)->toBe(1)
        ->and($resolver->remaining('docs.google.com'))->toBe([['127.0.0.1']])
        ->and($http->calls[0]['url'])->toBe(google_sheet_export())
        ->and($http->calls[0]['ip'])->toBe('8.8.8.8')
        ->and($http->calls[0]['connect_timeout'])->toBe(SpreadsheetLimits::CONNECT_TIMEOUT_SECONDS)
        ->and($http->calls[0]['timeout'])->toBe(SpreadsheetLimits::TOTAL_TIMEOUT_SECONDS)
        ->and($http->calls[0]['max_bytes'])->toBe(SpreadsheetLimits::MAX_BYTES)
        ->and($sheet->rows)->toBe([['Ada']]);
});

it('pins each redirect hop and does not use a later private answer', function () {
    $resolver = google_sheet_resolver([
        'docs.google.com' => [
            ['8.8.8.8'],
            ['10.0.0.1'],
        ],
        'doc-0.googleusercontent.com' => [
            ['142.250.1.2'],
            ['192.168.0.5'],
        ],
    ]);
    $http = google_sheet_http([
        new PinnedHttpResponse(302, '', 'https://doc-0.googleusercontent.com/download?id=1', '', false),
        new PinnedHttpResponse(200, "first_name\nAda\n", null, 'text/csv', false),
    ]);
    $body = (new GoogleSheetClient($resolver, $http))->fetch(google_sheet_link());

    expect($body)->toBe("first_name\nAda\n")
        ->and($resolver->calls)->toBe(2)
        ->and($resolver->hosts)->toBe(['docs.google.com', 'doc-0.googleusercontent.com'])
        ->and($resolver->remaining('docs.google.com'))->toBe([['10.0.0.1']])
        ->and($resolver->remaining('doc-0.googleusercontent.com'))->toBe([['192.168.0.5']])
        ->and($http->calls[0]['ip'])->toBe('8.8.8.8')
        ->and($http->calls[1]['ip'])->toBe('142.250.1.2')
        ->and($http->calls[1]['url'])->toBe('https://doc-0.googleusercontent.com/download?id=1');
});

it('refuses a private address before connecting', function (string $address) {
    $resolver = google_sheet_resolver([
        'docs.google.com' => [[$address]],
    ]);
    $http = google_sheet_http([]);

    expect(fn () => (new GoogleSheetClient($resolver, $http))->fetch(google_sheet_link()))
        ->toThrow(ImportFetchException::class, ImportFetchException::MESSAGE_DOWNLOAD);
    expect($http->calls)->toBe([])
        ->and($resolver->calls)->toBe(1);
})->with([
    '127.0.0.1',
    '10.1.2.3',
    '169.254.169.254',
    '192.168.0.8',
    '::1',
]);

it('refuses a lookup that returns both a public and a private address', function () {
    $resolver = google_sheet_resolver([
        'docs.google.com' => [['8.8.8.8', '127.0.0.1']],
    ]);
    $http = google_sheet_http([]);

    expect(fn () => (new GoogleSheetClient($resolver, $http))->fetch(google_sheet_link()))
        ->toThrow(ImportFetchException::class, ImportFetchException::MESSAGE_DOWNLOAD);
    expect($http->calls)->toBe([]);
});

it('refuses a redirect whose host resolves to a private address', function () {
    $resolver = google_sheet_resolver([
        'docs.google.com' => [['8.8.8.8']],
        'doc-0.googleusercontent.com' => [['10.1.2.3']],
    ]);
    $http = google_sheet_http([
        new PinnedHttpResponse(302, '', 'https://doc-0.googleusercontent.com/download', '', false),
    ]);

    expect(fn () => (new GoogleSheetClient($resolver, $http))->fetch(google_sheet_link()))
        ->toThrow(ImportFetchException::class, ImportFetchException::MESSAGE_DOWNLOAD);
    expect($http->calls)->toHaveCount(1)
        ->and($resolver->hosts)->toBe(['docs.google.com', 'doc-0.googleusercontent.com']);
});

it('refuses redirects off the allow-list without resolving them', function (string $location, string $message) {
    $resolver = google_sheet_resolver([
        'docs.google.com' => [['8.8.8.8']],
    ]);
    $http = google_sheet_http([
        new PinnedHttpResponse(302, '', $location, '', false),
    ]);

    expect(fn () => (new GoogleSheetClient($resolver, $http))->fetch(google_sheet_link()))
        ->toThrow(ImportFetchException::class, $message);
    expect($resolver->hosts)->toBe(['docs.google.com'])
        ->and($http->calls)->toHaveCount(1);
})->with([
    ['https://evil.example/sheet.csv', ImportFetchException::MESSAGE_DOWNLOAD],
    ['https://googleusercontent.com/sheet.csv', ImportFetchException::MESSAGE_DOWNLOAD],
    ['https://evilgoogleusercontent.com/sheet.csv', ImportFetchException::MESSAGE_DOWNLOAD],
    ['https://docs.google.com.evil.com/sheet.csv', ImportFetchException::MESSAGE_DOWNLOAD],
    ['http://docs.google.com/spreadsheets/d/abcdefghijklmnopqrstuv/export?format=csv', ImportFetchException::MESSAGE_DOWNLOAD],
    ['https://accounts.google.com/ServiceLogin', ImportFetchException::MESSAGE_PRIVATE],
    ['//evil.example/sheet.csv', ImportFetchException::MESSAGE_DOWNLOAD],
]);

it('follows three redirects and refuses a fourth', function () {
    $resolver = google_sheet_resolver([
        'docs.google.com' => [['8.8.8.8']],
        'a.googleusercontent.com' => [['1.1.1.1']],
        'b.googleusercontent.com' => [['1.0.0.1']],
        'c.googleusercontent.com' => [['8.8.4.4']],
        'd.googleusercontent.com' => [['9.9.9.9']],
    ]);
    $allowed = google_sheet_http([
        new PinnedHttpResponse(302, '', 'https://a.googleusercontent.com/a', '', false),
        new PinnedHttpResponse(302, '', 'https://b.googleusercontent.com/b', '', false),
        new PinnedHttpResponse(302, '', 'https://c.googleusercontent.com/c', '', false),
        new PinnedHttpResponse(200, "first_name\nAda\n", null, 'text/csv', false),
    ]);
    expect((new GoogleSheetClient($resolver, $allowed))->fetch(google_sheet_link()))->toBe("first_name\nAda\n")
        ->and($allowed->calls)->toHaveCount(4);

    $blocked = google_sheet_http([
        new PinnedHttpResponse(302, '', 'https://a.googleusercontent.com/a', '', false),
        new PinnedHttpResponse(302, '', 'https://b.googleusercontent.com/b', '', false),
        new PinnedHttpResponse(302, '', 'https://c.googleusercontent.com/c', '', false),
        new PinnedHttpResponse(302, '', 'https://d.googleusercontent.com/d', '', false),
    ]);
    $resolver = google_sheet_resolver([
        'docs.google.com' => [['8.8.8.8']],
        'a.googleusercontent.com' => [['1.1.1.1']],
        'b.googleusercontent.com' => [['1.0.0.1']],
        'c.googleusercontent.com' => [['8.8.4.4']],
        'd.googleusercontent.com' => [['9.9.9.9']],
    ]);

    expect(fn () => (new GoogleSheetClient($resolver, $blocked))->fetch(google_sheet_link()))
        ->toThrow(ImportFetchException::class, ImportFetchException::MESSAGE_DOWNLOAD);
    expect($resolver->hosts)->not->toContain('d.googleusercontent.com')
        ->and($blocked->calls)->toHaveCount(4);
});

it('treats a private sheet as a sharing error', function (PinnedHttpResponse $response) {
    $resolver = google_sheet_resolver([
        'docs.google.com' => [['8.8.8.8']],
    ]);
    $http = google_sheet_http([$response]);

    expect(fn () => (new GoogleSheetClient($resolver, $http))->fetch(google_sheet_link()))
        ->toThrow(ImportFetchException::class, ImportFetchException::MESSAGE_PRIVATE);
})->with([
    new PinnedHttpResponse(401, '', null, 'text/html', false),
    new PinnedHttpResponse(403, '', null, '', false),
    new PinnedHttpResponse(200, '<!DOCTYPE html><html>Sign in</html>', null, 'text/html', false),
    new PinnedHttpResponse(200, '<html>Sign in</html>', null, 'text/plain', false),
]);

it('refuses a response larger than 5 MB', function () {
    $resolver = google_sheet_resolver([
        'docs.google.com' => [['8.8.8.8']],
    ]);
    $http = google_sheet_http([
        new PinnedHttpResponse(200, '', null, 'text/csv', true),
    ]);

    expect(fn () => (new GoogleSheetClient($resolver, $http))->fetch(google_sheet_link()))
        ->toThrow(ImportFetchException::class, ImportFetchException::MESSAGE_SIZE);
});

it('classifies blocked and public addresses', function (string $ip, bool $blocked) {
    expect(BlockedAddress::isBlocked($ip))->toBe($blocked);
})->with([
    ['0.0.0.0', true],
    ['0.1.2.3', true],
    ['10.0.0.1', true],
    ['10.255.255.255', true],
    ['100.64.0.1', true],
    ['100.127.255.255', true],
    ['100.63.1.1', false],
    ['100.128.0.1', false],
    ['127.0.0.1', true],
    ['169.254.169.254', true],
    ['169.254.0.1', true],
    ['172.16.0.1', true],
    ['172.31.255.255', true],
    ['172.15.255.255', false],
    ['172.32.0.1', false],
    ['192.0.0.1', true],
    ['192.0.2.1', true],
    ['192.0.1.1', false],
    ['192.168.1.1', true],
    ['198.18.0.1', true],
    ['198.19.255.1', true],
    ['198.20.0.1', false],
    ['198.51.100.1', true],
    ['203.0.113.1', true],
    ['203.0.114.1', false],
    ['223.0.0.1', false],
    ['224.0.0.1', true],
    ['239.255.255.255', true],
    ['240.0.0.1', true],
    ['255.255.255.255', true],
    ['8.8.8.8', false],
    ['1.1.1.1', false],
    ['142.250.190.14', false],
    ['11.0.0.1', false],
    ['::', true],
    ['::1', true],
    ['fc00::1', true],
    ['fd12::1', true],
    ['fe80::1', true],
    ['febf::1', true],
    ['ff02::1', true],
    ['::ffff:127.0.0.1', true],
    ['::ffff:10.0.0.1', true],
    ['::ffff:169.254.169.254', true],
    ['::ffff:8.8.8.8', false],
    ['2001:4860:4860::8888', false],
    ['not-an-ip', true],
]);

it('builds a curl resolve entry and refuses a blocked pin', function () {
    expect(CurlPinnedHttp::resolveOption('docs.google.com', 443, '8.8.8.8'))
        ->toBe(['docs.google.com:443:8.8.8.8'])
        ->and(CurlPinnedHttp::resolveOption('docs.google.com', 443, '2001:4860:4860::8888'))
        ->toBe(['docs.google.com:443:[2001:4860:4860::8888]']);

    expect(fn () => (new CurlPinnedHttp)->get('https://docs.google.com/', '127.0.0.1', 1, 1, 100))
        ->toThrow(ImportFetchException::class, ImportFetchException::MESSAGE_DOWNLOAD);
});

it('does not look up the metadata host or an address literal', function () {
    $resolver = new SystemHostResolver;

    expect($resolver->addresses('metadata.google.internal'))->toBe([])
        ->and($resolver->addresses('metadata.google.internal.'))->toBe([])
        ->and($resolver->addresses('8.8.8.8'))->toBe(['8.8.8.8'])
        ->and($resolver->addresses(''))->toBe([]);
});
