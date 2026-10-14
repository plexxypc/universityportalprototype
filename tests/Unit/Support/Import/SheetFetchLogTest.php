<?php

declare(strict_types=1);

use App\Exceptions\ImportFetchException;
use App\Support\Import\GoogleSheetClient;
use App\Support\Import\HostResolver;
use App\Support\Import\PinnedHttp;
use App\Support\Import\PinnedHttpResponse;
use App\Support\Import\SheetFetchLog;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

it('records a fixed category and leaves the link out of the log', function () {
    $lines = [];
    Log::listen(function (MessageLogged $event) use (&$lines): void {
        $lines[] = $event->message.' '.json_encode($event->context);
    });

    $link = 'https://docs.google.com/spreadsheets/d/abcdefghijklmnopqrstuv/edit';
    $resolver = new class implements HostResolver
    {
        public function addresses(string $host): array
        {
            return ['8.8.8.8', '127.0.0.1'];
        }
    };
    $http = new class implements PinnedHttp
    {
        public function get(string $url, string $pinned_ip, int $connect_timeout, int $timeout, int $max_bytes): PinnedHttpResponse
        {
            return new PinnedHttpResponse(200, 'first_name', null, 'text/csv', false);
        }
    };

    expect(fn () => (new GoogleSheetClient($resolver, $http))->fetch($link))
        ->toThrow(ImportFetchException::class, ImportFetchException::MESSAGE_DOWNLOAD);

    $logged = implode("\n", $lines);

    expect(SheetFetchLog::last())->toBe('sheet_fetch_failed category=blocked_address hop=1')
        ->and($logged)->toContain('sheet_fetch_failed category=blocked_address hop=1')
        ->and($logged)->not->toContain('abcdefghijklmnopqrstuv')
        ->and($logged)->not->toContain('docs.google.com')
        ->and($logged)->not->toContain('127.0.0.1');
});

it('names an allow-list miss, a private address, and a non-https redirect', function () {
    $link = 'https://docs.google.com/spreadsheets/d/abcdefghijklmnopqrstuv/edit';
    $resolver = new class implements HostResolver
    {
        public function addresses(string $host): array
        {
            return $host === 'docs.google.com' ? ['8.8.8.8'] : [];
        }
    };
    $http = new class implements PinnedHttp
    {
        public function get(string $url, string $pinned_ip, int $connect_timeout, int $timeout, int $max_bytes): PinnedHttpResponse
        {
            return new PinnedHttpResponse(302, '', 'https://evil.example/sheet.csv', '', false);
        }
    };

    expect(fn () => (new GoogleSheetClient($resolver, $http))->fetch($link))
        ->toThrow(ImportFetchException::class, ImportFetchException::MESSAGE_DOWNLOAD);
    expect(SheetFetchLog::last())->toBe('sheet_fetch_failed category=redirect_off_allowlist hop=2')
        ->and(SheetFetchLog::last())->not->toContain('evil.example');

    $private = new class implements HostResolver
    {
        public function addresses(string $host): array
        {
            return ['10.1.2.3'];
        }
    };
    $unused = new class implements PinnedHttp
    {
        public function get(string $url, string $pinned_ip, int $connect_timeout, int $timeout, int $max_bytes): PinnedHttpResponse
        {
            return new PinnedHttpResponse(200, '', null, 'text/csv', false);
        }
    };

    expect(fn () => (new GoogleSheetClient($private, $unused))->fetch($link))
        ->toThrow(ImportFetchException::class, ImportFetchException::MESSAGE_DOWNLOAD);
    expect(SheetFetchLog::last())->toBe('sheet_fetch_failed category=no_public_address hop=1');

    $relative = new class implements PinnedHttp
    {
        public function get(string $url, string $pinned_ip, int $connect_timeout, int $timeout, int $max_bytes): PinnedHttpResponse
        {
            return new PinnedHttpResponse(302, '', '/relative', '', false);
        }
    };

    expect(fn () => (new GoogleSheetClient($resolver, $relative))->fetch($link))
        ->toThrow(ImportFetchException::class, ImportFetchException::MESSAGE_DOWNLOAD);
    expect(SheetFetchLog::last())->toBe('sheet_fetch_failed category=redirect_off_allowlist hop=1');
});

it('maps curl error numbers and drops a category that is not on the list', function () {
    expect(SheetFetchLog::curlCategory(60))->toBe('tls')
        ->and(SheetFetchLog::curlCategory(28))->toBe('timeout')
        ->and(SheetFetchLog::curlCategory(7))->toBe('connect')
        ->and(SheetFetchLog::curlCategory(6))->toBe('dns')
        ->and(SheetFetchLog::curlCategory(23))->toBe('curl');

    SheetFetchLog::setHop(2);
    SheetFetchLog::failed('https://evil.example/spreadsheets/d/abcdefghijklmnopqrstuv', 60);

    expect(SheetFetchLog::last())->toBe('sheet_fetch_failed category=unknown hop=2 curl_errno=60')
        ->and(SheetFetchLog::last())->not->toContain('evil.example')
        ->and(SheetFetchLog::last())->not->toContain('abcdefghijklmnopqrstuv');

    SheetFetchLog::setHop(1);
    SheetFetchLog::failed('tls', 60);

    expect(SheetFetchLog::last())->toBe('sheet_fetch_failed category=tls hop=1 curl_errno=60');
});
