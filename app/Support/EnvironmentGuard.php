<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * Refuse to boot outside local when required settings are missing or debug is on.
 */
final class EnvironmentGuard
{
    public function __construct(private readonly Application $app) {}

    /**
     * Throw a clear error when this environment is unsafe to start.
     *
     * @throws RuntimeException
     */
    public function enforce(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        $problems = $this->problems();

        if ($problems === []) {
            return;
        }

        throw new RuntimeException('Refusing to boot. '.implode('; ', $problems).'.');
    }

    /**
     * Human-readable configuration problems for a non-local boot.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if ((bool) config('app.debug')) {
            $problems[] = 'APP_DEBUG must be false';
        }

        $connection = config('portal.required.db_connection');

        if ($this->isMissing($connection)) {
            $problems[] = 'DB_CONNECTION is missing';
        } elseif ($connection !== 'mysql') {
            $problems[] = 'DB_CONNECTION must be mysql';
        }

        foreach ($this->requiredSettings() as $name => $value) {
            if ($this->isMissing($value)) {
                $problems[] = $name.' is missing';
            }
        }

        return $problems;
    }

    /**
     * Settings that have no safe framework fallback.
     *
     * @return array<string, mixed>
     */
    private function requiredSettings(): array
    {
        return [
            'APP_KEY' => config('portal.required.app_key'),
            'APP_URL' => config('portal.required.app_url'),
            'DB_HOST' => config('portal.required.db_host'),
            'DB_PORT' => config('portal.required.db_port'),
            'DB_DATABASE' => config('portal.required.db_database'),
            'DB_USERNAME' => config('portal.required.db_username'),
            'DB_PASSWORD' => config('portal.required.db_password'),
        ];
    }

    /**
     * True when a required value was not provided.
     */
    private function isMissing(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_int($value) || is_float($value)) {
            return false;
        }

        return true;
    }
}
