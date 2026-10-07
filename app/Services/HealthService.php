<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use PDO;
use Pdo\Mysql;
use RuntimeException;
use Throwable;

/**
 * Coarse status for the public health endpoint.
 *
 * The heartbeat shows that the scheduler command ran recently. It does not
 * show that the queue worker is consuming jobs.
 */
final class HealthService
{
    /**
     * Short-lived MySQL handle used only by this probe.
     */
    private ?PDO $probe = null;

    /**
     * Build the public status payload.
     *
     * The probe opens its own PDO. The shared mysql connection is not given
     * a timeout, and the process read timeout is restored before this returns.
     *
     * @return array{
     *     status: string,
     *     database: string,
     *     heartbeat: string,
     *     mail_driver: string,
     *     payment_provider: string,
     *     environment: string,
     *     version: string|null
     * }
     */
    public function snapshot(): array
    {
        $previous_read_timeout = ini_get('mysqlnd.net_read_timeout');
        ini_set('mysqlnd.net_read_timeout', (string) $this->timeoutSeconds());

        try {
            $database = $this->databaseStatus();
            $heartbeat = $database !== 'ok' && $this->heartbeatUsesDatabase()
                ? 'stale'
                : $this->heartbeatStatus();
        } finally {
            $this->probe = null;

            if (is_string($previous_read_timeout)) {
                ini_set('mysqlnd.net_read_timeout', $previous_read_timeout);
            }
        }

        $degraded = $database !== 'ok' || $heartbeat !== 'ok';

        return [
            'status' => $degraded ? 'degraded' : 'ok',
            'database' => $database,
            'heartbeat' => $heartbeat,
            'mail_driver' => (string) config('mail.default'),
            'payment_provider' => (string) config('portal.payment_provider'),
            'environment' => (string) app()->environment(),
            'version' => $this->version(),
        ];
    }

    /**
     * HTTP status for a snapshot. Degraded checks answer 503.
     *
     * @param  array{status: string}  $snapshot
     */
    public function httpStatus(array $snapshot): int
    {
        return $snapshot['status'] === 'ok' ? 200 : 503;
    }

    /**
     * Reachability of MySQL via one short-timeout PDO. No retry.
     */
    private function databaseStatus(): string
    {
        try {
            $this->openProbe()->query('select 1 as health_check');

            return 'ok';
        } catch (Throwable) {
            return 'unreachable';
        }
    }

    /**
     * Whether the cached scheduler time is inside the recent window.
     *
     * A recent value shows that portal:heartbeat ran. It does not show that
     * the queue worker is consuming jobs.
     */
    private function heartbeatStatus(): string
    {
        try {
            $recorded_at = $this->heartbeatValue();
        } catch (Throwable) {
            return 'stale';
        }

        if (! is_int($recorded_at) && ! (is_string($recorded_at) && ctype_digit($recorded_at))) {
            return 'stale';
        }

        $age_seconds = now()->getTimestamp() - (int) $recorded_at;

        if ($age_seconds > $this->recentSeconds()) {
            return 'stale';
        }

        return 'ok';
    }

    /**
     * Read the heartbeat from the configured cache store.
     *
     * Database cache is read on the probe PDO so a stalled server cannot use
     * the application's normal connection.
     */
    private function heartbeatValue(): mixed
    {
        if (! $this->heartbeatUsesDatabase()) {
            return Cache::get($this->heartbeatKey());
        }

        return $this->databaseHeartbeatValue();
    }

    /**
     * Read the heartbeat row through the probe connection.
     */
    private function databaseHeartbeatValue(): mixed
    {
        $table = config('cache.stores.database.table');

        if (! is_string($table) || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return null;
        }

        $statement = $this->openProbe()->prepare(
            'select `value`, `expiration` from `'.$table.'` where `key` = ? limit 1',
        );
        $statement->execute([(string) config('cache.prefix').$this->heartbeatKey()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (! is_array($row) || (int) $row['expiration'] <= now()->getTimestamp()) {
            return null;
        }

        $value = unserialize((string) $row['value'], ['allowed_classes' => false]);

        return $value === false ? null : $value;
    }

    /**
     * True when the default cache store reads MySQL.
     */
    private function heartbeatUsesDatabase(): bool
    {
        $store_name = (string) config('cache.default');

        return config('cache.stores.'.$store_name.'.driver') === 'database';
    }

    /**
     * Open one MySQL PDO for this probe.
     *
     * new PDO honours ATTR_TIMEOUT as the connect timeout. Laravel's connector
     * uses PDO::connect on PHP 8.4 and retries a lost connection, which let a
     * blackhole run for about 12 seconds. This handle is not the shared
     * application connection, and its options are not written back to config.
     *
     * @throws RuntimeException
     */
    private function openProbe(): PDO
    {
        if ($this->probe instanceof PDO) {
            return $this->probe;
        }

        $source = config('database.connections.mysql');

        if (! is_array($source)) {
            throw new RuntimeException('MySQL connection is not configured.');
        }

        $timeout_seconds = $this->timeoutSeconds();
        $options = is_array($source['options'] ?? null) ? $source['options'] : [];
        $options[PDO::ATTR_TIMEOUT] = $timeout_seconds;
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $options[Mysql::ATTR_INIT_COMMAND] = 'SET SESSION max_execution_time = '.($timeout_seconds * 1000);

        $this->probe = new PDO(
            $this->dsn($source),
            (string) ($source['username'] ?? ''),
            (string) ($source['password'] ?? ''),
            $options,
        );

        return $this->probe;
    }

    /**
     * DSN for the probe, using the configured socket or host.
     *
     * @param  array<string, mixed>  $source
     */
    private function dsn(array $source): string
    {
        $database = (string) ($source['database'] ?? '');
        $socket = $source['unix_socket'] ?? '';

        if (is_string($socket) && $socket !== '') {
            return 'mysql:unix_socket='.$socket.';dbname='.$database;
        }

        $host = (string) ($source['host'] ?? '127.0.0.1');
        $port = (string) ($source['port'] ?? '3306');

        return 'mysql:host='.$host.';port='.$port.';dbname='.$database;
    }

    /**
     * Optional release id. Empty configuration stays null.
     */
    private function version(): ?string
    {
        $version = config('portal.version');

        if (! is_string($version) || trim($version) === '') {
            return null;
        }

        return trim($version);
    }

    /**
     * Cache key written by portal:heartbeat.
     */
    private function heartbeatKey(): string
    {
        return (string) config('portal.health.heartbeat_key');
    }

    /**
     * Seconds after which a heartbeat is stale.
     */
    private function recentSeconds(): int
    {
        $seconds = (int) config('portal.health.heartbeat_recent_seconds');

        return $seconds > 0 ? $seconds : 120;
    }

    /**
     * Connect and read budget for the probe, in seconds.
     */
    private function timeoutSeconds(): int
    {
        $seconds = (int) config('portal.health.database_timeout_seconds');

        return $seconds > 0 ? $seconds : 3;
    }
}
