<?php

declare(strict_types=1);

use App\Support\DatabaseTls;
use App\Support\EnvironmentGuard;
use Pdo\Mysql;

it('keeps the session cookie http only, lax, and secure outside local', function () {
    expect(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax')
        ->and(config('session.secure'))->toBeTrue();
});

it('uses the database for sessions, cache, and the queue by default', function () {
    $session = file_get_contents(config_path('session.php'));
    $cache = file_get_contents(config_path('cache.php'));
    $queue = file_get_contents(config_path('queue.php'));

    expect($session)->toContain("env('SESSION_DRIVER', 'database')")
        ->and($cache)->toContain("env('CACHE_STORE', 'database')")
        ->and($queue)->toContain("env('QUEUE_CONNECTION', 'database')");
});

it('stores the upload limits and capacity placeholders', function () {
    expect(config('portal.uploads.document_max_kilobytes'))->toBe(5120)
        ->and(config('portal.uploads.logo_max_kilobytes'))->toBe(1024)
        ->and(config('portal.uploads.import_max_kilobytes'))->toBe(5120)
        ->and(config('portal.uploads.import_max_rows'))->toBe(5000)
        ->and(config('portal.uploads.allowed_document_extensions'))->toBe(['pdf', 'jpg', 'jpeg', 'png'])
        ->and(config('portal.capacity.launch_students'))->toBe(1000)
        ->and(config('portal.capacity.expansion_students'))->toBe(2000)
        ->and(config('portal.capacity.peak_concurrent_users'))->toBe(300)
        ->and(config('portal.capacity.default_page_size'))->toBe(25);
});

it('leaves mysql tls unset when no ca is configured', function () {
    expect(config('database.connections.mysql.options'))->not->toHaveKey(Mysql::ATTR_SSL_CA);
});

it('writes certificate text to a file before enabling tls', function () {
    $certificate = "-----BEGIN CERTIFICATE-----\nLOCAL-TEST-CA\n-----END CERTIFICATE-----\n";
    $path = storage_path('app/certs/mysql-ca.pem');

    try {
        config(['database.connections.mysql.ssl_ca' => $certificate]);

        app(DatabaseTls::class)->apply();

        expect(config('database.connections.mysql.options')[Mysql::ATTR_SSL_CA])->toBe($path)
            ->and(file_get_contents($path))->toBe($certificate);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('accepts a ca file path for tls', function () {
    $path = storage_path('app/certs/mysql-ca-path.pem');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, "-----BEGIN CERTIFICATE-----\nPATH\n-----END CERTIFICATE-----\n");

    try {
        config(['database.connections.mysql.ssl_ca' => $path]);

        app(DatabaseTls::class)->apply();

        expect(config('database.connections.mysql.options')[Mysql::ATTR_SSL_CA])->toBe($path);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('refuses a ca value that is not a file or certificate text', function () {
    config(['database.connections.mysql.ssl_ca' => storage_path('app/certs/missing-ca.pem')]);

    expect(fn () => app(DatabaseTls::class)->apply())
        ->toThrow(RuntimeException::class, 'DB_SSL_CA must be a readable CA certificate file');
});

it('refuses to boot outside local when debug is enabled', function () {
    config(['app.debug' => true]);

    expect(fn () => app(EnvironmentGuard::class)->enforce())
        ->toThrow(RuntimeException::class, 'APP_DEBUG must be false');
});

it('refuses to boot outside local when a required setting is missing', function () {
    config(['portal.required.app_key' => '']);

    expect(fn () => app(EnvironmentGuard::class)->enforce())
        ->toThrow(RuntimeException::class, 'APP_KEY is missing');
});

it('refuses a non-mysql connection outside local', function () {
    config(['portal.required.db_connection' => 'sqlite']);

    expect(fn () => app(EnvironmentGuard::class)->enforce())
        ->toThrow(RuntimeException::class, 'DB_CONNECTION must be mysql');
});

it('allows local to boot with debug on and empty settings', function () {
    $this->app['env'] = 'local';

    config([
        'app.debug' => true,
        'portal.required.app_key' => '',
        'portal.required.db_connection' => '',
    ]);

    app(EnvironmentGuard::class)->enforce();

    expect($this->app->environment('local'))->toBeTrue();
});
