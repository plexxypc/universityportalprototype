<?php

declare(strict_types=1);

/**
 * Block until the throwaway CI MySQL database accepts a connection.
 *
 * The Pest job also sets a Docker health check, so GitHub does not start
 * steps until mysqladmin can ping the server. This script runs immediately
 * before the suite and uses the same host, database, user, and password
 * that phpunit.xml forces.
 */
exit(wait_for_mysql());

/**
 * Return 0 when a connection succeeds, or 1 when the deadline passes.
 */
function wait_for_mysql(int $timeout_seconds = 90): int
{
    $host = mysql_setting('DB_HOST', '127.0.0.1');
    $port = mysql_setting('DB_PORT', '3306');
    $database = mysql_setting('DB_DATABASE', 'university_portal_testing');
    $username = mysql_setting('DB_USERNAME', 'portal');
    $password = mysql_setting('DB_PASSWORD', 'portal');
    $deadline = time() + $timeout_seconds;

    do {
        try {
            new PDO(
                "mysql:host={$host};port={$port};dbname={$database}",
                $username,
                $password,
            );

            fwrite(STDOUT, "MySQL accepted a connection.\n");

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception->getMessage()."\n");
            sleep(3);
        }
    } while (time() < $deadline);

    fwrite(STDERR, "MySQL did not become healthy.\n");

    return 1;
}

/**
 * Read one throwaway database setting, falling back when it is unset.
 */
function mysql_setting(string $name, string $default): string
{
    $value = getenv($name);

    if (! is_string($value) || trim($value) === '') {
        return $default;
    }

    return $value;
}
