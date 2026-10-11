<?php

declare(strict_types=1);

use App\Services\MailService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    DB::table('jobs')->delete();
    DB::table('failed_jobs')->delete();
    Cache::store('database')->forget(MailService::CIRCUIT_PAUSE_KEY);
});

it('returns the healthy shape and keeps the framework health route', function () {
    config(['portal.version' => 'abc123']);
    $this->artisan('portal:heartbeat')->assertSuccessful();

    $response = $this->get('/health');

    $response->assertOk()->assertExactJson([
        'status' => 'ok',
        'database' => 'ok',
        'heartbeat' => 'ok',
        'queue' => 'ok',
        'mail' => 'log',
        'mail_driver' => config('mail.default'),
        'payment_provider' => config('portal.payment_provider'),
        'environment' => 'testing',
        'version' => 'abc123',
    ]);

    $this->get('/up')->assertOk();
});

it('returns degraded when the database check fails', function () {
    $this->artisan('portal:heartbeat')->assertSuccessful();
    config(['database.connections.mysql.port' => 1]);

    $response = $this->get('/health');

    $response->assertStatus(503)->assertExactJson([
        'status' => 'degraded',
        'database' => 'unreachable',
        'heartbeat' => 'ok',
        'queue' => 'failing',
        'mail' => 'log',
        'mail_driver' => config('mail.default'),
        'payment_provider' => config('portal.payment_provider'),
        'environment' => 'testing',
        'version' => null,
    ]);

    expect($response->getContent())
        ->not->toContain('SQLSTATE')
        ->not->toContain('127.0.0.1')
        ->not->toContain('Connection refused');
});

it('returns degraded when the heartbeat is stale', function () {
    Cache::put(
        (string) config('portal.health.heartbeat_key'),
        now()->subSeconds(121)->getTimestamp(),
        600,
    );

    $this->get('/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('database', 'ok')
        ->assertJsonPath('heartbeat', 'stale');
});

it('does not include secrets or internal names', function () {
    $this->artisan('portal:heartbeat')->assertSuccessful();

    config([
        'app.key' => 'health-secret-app-key',
        'mail.mailers.smtp.password' => 'health-secret-mail-password',
        'services.brevo.secret' => 'health-secret-brevo-key',
        'portal.payment_secret' => 'health-secret-payment-key',
    ]);

    $body = $this->get('/health')->assertOk()->getContent();

    expect($body)
        ->not->toContain('health-secret-app-key')
        ->not->toContain('health-secret-mail-password')
        ->not->toContain('health-secret-brevo-key')
        ->not->toContain('health-secret-payment-key')
        ->not->toContain((string) config('database.connections.mysql.host'))
        ->not->toContain((string) config('database.connections.mysql.username'))
        ->not->toContain((string) config('database.connections.mysql.password'))
        ->not->toContain((string) config('database.connections.mysql.database'))
        ->not->toContain('Stack trace')
        ->not->toContain('vendor/laravel');
});

it('sends no-store and sets no cookies', function () {
    $this->artisan('portal:heartbeat')->assertSuccessful();

    $response = $this->get('/health');

    $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->has('set-cookie'))->toBeFalse();
});

it('keys the health rate limit on the socket address', function () {
    $this->artisan('portal:heartbeat')->assertSuccessful();

    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.8',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
    ])->get('/health')->assertOk();

    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.8',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.11',
    ])->get('/health')->assertOk();

    $limiter = new RateLimiter(Cache::store((string) config('portal.health.rate_limit_store')));

    expect($limiter->attempts('health10.0.0.8'))->toBe(2)
        ->and($limiter->attempts('health203.0.113.10'))->toBe(0)
        ->and($limiter->attempts('health203.0.113.11'))->toBe(0);
});

it('returns 429 on the 61st request in a minute', function () {
    $this->artisan('portal:heartbeat')->assertSuccessful();

    for ($attempt = 0; $attempt < 60; $attempt++) {
        $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.8',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.'.$attempt,
        ])->get('/health')->assertOk();
    }

    $limited = $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.8',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.99',
    ])->get('/health');

    $limited->assertTooManyRequests();

    $limiter = new RateLimiter(Cache::store((string) config('portal.health.rate_limit_store')));

    expect($limited->headers->getCookies())->toBe([])
        ->and($limited->headers->has('set-cookie'))->toBeFalse()
        ->and($limiter->attempts('health10.0.0.8'))->toBe(60)
        ->and($limiter->attempts('health198.51.100.99'))->toBe(0);
});

it('fails a blackholed database within a few seconds without changing the application connection', function () {
    $read_timeout = ini_get('mysqlnd.net_read_timeout');
    $options = config('database.connections.mysql.options');

    config(['database.connections.mysql.host' => '192.0.2.1']);

    $started = microtime(true);
    $response = $this->get('/health');
    $elapsed = microtime(true) - $started;

    $response->assertStatus(503)->assertJsonPath('database', 'unreachable');

    expect($elapsed)->toBeLessThan(5)
        ->and(ini_get('mysqlnd.net_read_timeout'))->toBe($read_timeout)
        ->and(config('database.connections.mysql.options'))->toBe($options)
        ->and($response->getContent())->not->toContain('192.0.2.1');
});

it('reports queue and mail as status words and does not leak a key', function () {
    $this->artisan('portal:heartbeat')->assertSuccessful();

    $secret = 'health-probe-secret-value';
    config([
        'mail.default' => $secret,
        'mail.mailers.brevo.key' => $secret,
        'mail.from.address' => 'hidden-from@example.test',
    ]);

    $response = $this->get('/health');
    $body = (string) $response->getContent();
    $leaked = str_contains($body, $secret) || str_contains($body, 'hidden-from@example.test');

    expect($leaked)->toBeFalse()
        ->and($response->json('mail_driver'))->toBe('unconfigured')
        ->and($response->json('mail'))->toBe('unconfigured')
        ->and(is_string($response->json('queue')))->toBeTrue();
});

it('reports a queue backlog without a count', function () {
    $this->artisan('portal:heartbeat')->assertSuccessful();
    health_insert_job(now()->subMinutes(11)->getTimestamp());
    health_insert_job(now()->subMinutes(11)->getTimestamp());

    $response = $this->get('/health')->assertStatus(503);

    expect($response->json('queue'))->toBe('backlog')
        ->and($response->json('status'))->toBe('degraded')
        ->and(array_key_exists('count', $response->json()))->toBeFalse();
});

it('reports failing jobs ahead of a backlog', function () {
    $this->artisan('portal:heartbeat')->assertSuccessful();
    health_insert_job(now()->subMinutes(11)->getTimestamp());
    health_insert_failure();

    $response = $this->get('/health')->assertStatus(503);

    expect($response->json('queue'))->toBe('failing');
});

it('reports mail misconfigured while the circuit breaker is open', function () {
    $this->artisan('portal:heartbeat')->assertSuccessful();
    Cache::store('database')->put(MailService::CIRCUIT_PAUSE_KEY, true, 60);

    $response = $this->get('/health')->assertStatus(503);

    expect($response->json('mail'))->toBe('misconfigured')
        ->and($response->json('mail_driver'))->toBe('array');
});

it('reports brevo when the adapter has a host and a key', function () {
    $this->artisan('portal:heartbeat')->assertSuccessful();
    config([
        'mail.default' => 'brevo',
        'mail.mailers.brevo.api_url' => 'https://api.brevo.com',
        'mail.mailers.brevo.key' => 'health-probe-secret-value',
    ]);

    $response = $this->get('/health');
    $leaked = str_contains((string) $response->getContent(), 'health-probe-secret-value');

    expect($leaked)->toBeFalse()
        ->and($response->json('mail'))->toBe('brevo')
        ->and($response->json('mail_driver'))->toBe('brevo');
});

it('reports brevo unconfigured when the key is missing', function () {
    $this->artisan('portal:heartbeat')->assertSuccessful();
    config([
        'mail.default' => 'brevo',
        'mail.mailers.brevo.api_url' => 'https://api.brevo.com',
        'mail.mailers.brevo.key' => '',
    ]);

    $this->get('/health')
        ->assertStatus(503)
        ->assertJsonPath('mail', 'unconfigured');
});

/**
 * Insert one waiting job. The payload is empty.
 */
function health_insert_job(int $available_at): void
{
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => $available_at,
        'created_at' => now()->getTimestamp(),
    ]);
}

/**
 * Insert one failed job. The payload and exception text are fixed and empty of secrets.
 */
function health_insert_failure(): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'failed',
        'failed_at' => now(),
    ]);
}
