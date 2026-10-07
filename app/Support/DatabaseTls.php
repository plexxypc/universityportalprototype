<?php

declare(strict_types=1);

namespace App\Support;

use Pdo\Mysql;
use RuntimeException;

/**
 * Apply an optional MySQL CA certificate for Aiven TLS.
 *
 * DB_SSL_CA may be a file path or the certificate text. Text is written to
 * storage so PDO can read a path. Local development leaves the variable empty.
 */
final class DatabaseTls
{
    /**
     * Point the MySQL connections at a CA file when one was configured.
     *
     * @throws RuntimeException
     */
    public function apply(): void
    {
        foreach (['mysql', 'mariadb'] as $connection) {
            $certificate = config('database.connections.'.$connection.'.ssl_ca');

            if (! is_string($certificate) || trim($certificate) === '') {
                continue;
            }

            $path = str_contains($certificate, 'BEGIN CERTIFICATE')
                ? $this->writeCertificate($certificate)
                : $this->certificatePath($certificate);

            $options = config('database.connections.'.$connection.'.options');

            if (! is_array($options)) {
                $options = [];
            }

            $options[Mysql::ATTR_SSL_CA] = $path;

            config([
                'database.connections.'.$connection.'.options' => $options,
            ]);
        }
    }

    /**
     * Persist certificate text where PDO can read it.
     *
     * @throws RuntimeException
     */
    private function writeCertificate(string $certificate): string
    {
        $path = storage_path('app/certs/mysql-ca.pem');
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Refusing to boot. Could not create the database CA certificate directory.');
        }

        if (file_put_contents($path, $certificate) === false) {
            throw new RuntimeException('Refusing to boot. Could not write the database CA certificate.');
        }

        return $path;
    }

    /**
     * Use a CA path only when the file is readable.
     *
     * @throws RuntimeException
     */
    private function certificatePath(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException('Refusing to boot. DB_SSL_CA must be a readable CA certificate file or the certificate text.');
        }

        return $path;
    }
}
