<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Shell that can run docker/entrypoint.sh, plus a PATH that contains awk and openssl.
 *
 * @return array{binary: string, path: string}
 */
function mysql_ca_shell(): array
{
    $path = (string) getenv('PATH');
    $git_sh = 'C:\\Program Files\\Git\\bin\\sh.exe';
    $git_usr = 'C:\\Program Files\\Git\\usr\\bin';

    if (PHP_OS_FAMILY === 'Windows' && is_file($git_sh)) {
        return [
            'binary' => $git_sh,
            'path' => $git_usr.PATH_SEPARATOR.$path,
        ];
    }

    return [
        'binary' => 'sh',
        'path' => $path,
    ];
}

/**
 * openssl binary used to mint a throwaway public certificate.
 */
function mysql_ca_openssl(): string
{
    $git_openssl = 'C:\\Program Files\\Git\\usr\\bin\\openssl.exe';

    if (PHP_OS_FAMILY === 'Windows' && is_file($git_openssl)) {
        return $git_openssl;
    }

    return 'openssl';
}

/**
 * Public test certificate. The matching private key is deleted immediately.
 */
function mysql_ca_sample_pem(): string
{
    static $pem = null;

    if (is_string($pem)) {
        return $pem;
    }

    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mysql-ca-source-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $certificate_path = $directory.DIRECTORY_SEPARATOR.'source.pem';
    $key_path = $directory.DIRECTORY_SEPARATOR.'source.key';

    try {
        $process = new Process([
            mysql_ca_openssl(),
            'req',
            '-x509',
            '-newkey',
            'rsa:2048',
            '-keyout',
            $key_path,
            '-out',
            $certificate_path,
            '-days',
            '1',
            '-nodes',
            '-subj',
            '/CN=portal-ca-test',
        ]);
        $process->run();

        expect($process->isSuccessful())->toBeTrue();

        $pem = (string) file_get_contents($certificate_path);
    } finally {
        if (is_file($key_path)) {
            unlink($key_path);
        }

        if (is_file($certificate_path)) {
            unlink($certificate_path);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    return $pem;
}

/**
 * Base64 body of a PEM certificate, ignoring headers and line breaks.
 */
function mysql_ca_pem_body(string $pem): string
{
    $without_headers = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----/', '', $pem);

    return (string) preg_replace('/\s+/', '', (string) $without_headers);
}

/**
 * Run the entrypoint CA writer.
 *
 * @param  array<string, string>  $environment
 */
function run_mysql_ca_entrypoint(array $environment): Process
{
    $shell = mysql_ca_shell();
    $root = dirname(__DIR__, 2);
    $process = new Process(
        [$shell['binary'], $root.DIRECTORY_SEPARATOR.'docker'.DIRECTORY_SEPARATOR.'entrypoint.sh', '--write-database-ca'],
        $root,
        [
            'PATH' => $shell['path'],
            'DB_SSL_CA' => $environment['DB_SSL_CA'],
            'MYSQL_CA_DIRECTORY' => $environment['MYSQL_CA_DIRECTORY'],
        ],
    );
    $process->setTimeout(30);
    $process->run();

    return $process;
}

it('rebuilds a certificate whose line breaks were removed', function () {
    $pem = mysql_ca_sample_pem();
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mysql-ca-flat-'.bin2hex(random_bytes(4));
    mkdir($directory);

    try {
        $process = run_mysql_ca_entrypoint([
            'DB_SSL_CA' => str_replace(["\r\n", "\n", "\r"], '', $pem),
            'MYSQL_CA_DIRECTORY' => str_replace('\\', '/', $directory),
        ]);

        $written = (string) file_get_contents($directory.DIRECTORY_SEPARATOR.'mysql-ca.pem');

        expect($process->isSuccessful())->toBeTrue()
            ->and($process->getErrorOutput())->toContain('Database CA certificate written (1 block(s)).')
            ->and($process->getOutput())->not->toContain('BEGIN CERTIFICATE')
            ->and($process->getErrorOutput())->not->toContain(substr(mysql_ca_pem_body($pem), 0, 48))
            ->and(mysql_ca_pem_body($written))->toBe(mysql_ca_pem_body($pem))
            ->and($written)->toContain("-----BEGIN CERTIFICATE-----\n")
            ->and(max(array_map('strlen', explode("\n", trim($written)))))->toBeLessThanOrEqual(64);
    } finally {
        $written_path = $directory.DIRECTORY_SEPARATOR.'mysql-ca.pem';

        if (is_file($written_path)) {
            unlink($written_path);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('rebuilds a certificate whose line breaks were replaced with spaces', function () {
    $pem = mysql_ca_sample_pem();
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mysql-ca-spaces-'.bin2hex(random_bytes(4));
    mkdir($directory);

    try {
        $process = run_mysql_ca_entrypoint([
            'DB_SSL_CA' => preg_replace("/\r\n|\n|\r/", ' ', $pem) ?? $pem,
            'MYSQL_CA_DIRECTORY' => str_replace('\\', '/', $directory),
        ]);

        $written = (string) file_get_contents($directory.DIRECTORY_SEPARATOR.'mysql-ca.pem');

        expect($process->isSuccessful())->toBeTrue()
            ->and($process->getErrorOutput())->toContain('Database CA certificate written (1 block(s)).')
            ->and(mysql_ca_pem_body($written))->toBe(mysql_ca_pem_body($pem));
    } finally {
        $written_path = $directory.DIRECTORY_SEPARATOR.'mysql-ca.pem';

        if (is_file($written_path)) {
            unlink($written_path);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('rebuilds a certificate whose line breaks were stored as literal backslash-n', function () {
    $pem = mysql_ca_sample_pem();
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mysql-ca-escaped-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $escaped = '"'.str_replace("\n", '\\n', trim($pem)).'"';

    try {
        $process = run_mysql_ca_entrypoint([
            'DB_SSL_CA' => $escaped,
            'MYSQL_CA_DIRECTORY' => str_replace('\\', '/', $directory),
        ]);

        $written = (string) file_get_contents($directory.DIRECTORY_SEPARATOR.'mysql-ca.pem');

        expect($process->isSuccessful())->toBeTrue()
            ->and($process->getErrorOutput())->toContain('Database CA certificate written (1 block(s)).')
            ->and(mysql_ca_pem_body($written))->toBe(mysql_ca_pem_body($pem));
    } finally {
        $written_path = $directory.DIRECTORY_SEPARATOR.'mysql-ca.pem';

        if (is_file($written_path)) {
            unlink($written_path);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('rebuilds every certificate in a flattened bundle', function () {
    $pem = trim(mysql_ca_sample_pem());
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mysql-ca-bundle-'.bin2hex(random_bytes(4));
    mkdir($directory);

    try {
        $process = run_mysql_ca_entrypoint([
            'DB_SSL_CA' => str_replace("\n", '\\n', $pem.'\n'.$pem),
            'MYSQL_CA_DIRECTORY' => str_replace('\\', '/', $directory),
        ]);

        $written = (string) file_get_contents($directory.DIRECTORY_SEPARATOR.'mysql-ca.pem');

        expect($process->isSuccessful())->toBeTrue()
            ->and($process->getErrorOutput())->toContain('Database CA certificate written (2 block(s)).')
            ->and(substr_count($written, '-----BEGIN CERTIFICATE-----'))->toBe(2)
            ->and(mysql_ca_pem_body($written))->toBe(mysql_ca_pem_body($pem).mysql_ca_pem_body($pem));
    } finally {
        $written_path = $directory.DIRECTORY_SEPARATOR.'mysql-ca.pem';

        if (is_file($written_path)) {
            unlink($written_path);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('refuses a value that is not a certificate without printing it', function () {
    $secret = 'portal-ca-secret-'.bin2hex(random_bytes(8));
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mysql-ca-reject-'.bin2hex(random_bytes(4));
    mkdir($directory);

    try {
        $process = run_mysql_ca_entrypoint([
            'DB_SSL_CA' => $secret,
            'MYSQL_CA_DIRECTORY' => str_replace('\\', '/', $directory),
        ]);
        $combined = $process->getOutput().$process->getErrorOutput();

        expect($process->isSuccessful())->toBeFalse()
            ->and($combined)->toContain('DB_SSL_CA must be a PEM certificate')
            ->and($combined)->toContain('The value was not logged.')
            ->and($combined)->not->toContain($secret)
            ->and(is_file($directory.DIRECTORY_SEPARATOR.'mysql-ca.pem'))->toBeFalse();
    } finally {
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('refuses a certificate header with an invalid body without printing it', function () {
    $secret = 'not-base64-'.bin2hex(random_bytes(8));
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mysql-ca-bad-body-'.bin2hex(random_bytes(4));
    mkdir($directory);

    try {
        $process = run_mysql_ca_entrypoint([
            'DB_SSL_CA' => "-----BEGIN CERTIFICATE-----\\n{$secret}!\\n-----END CERTIFICATE-----",
            'MYSQL_CA_DIRECTORY' => str_replace('\\', '/', $directory),
        ]);
        $combined = $process->getOutput().$process->getErrorOutput();

        expect($process->isSuccessful())->toBeFalse()
            ->and($combined)->toContain('DB_SSL_CA has a certificate header but the body is not valid PEM')
            ->and($combined)->not->toContain($secret)
            ->and(is_file($directory.DIRECTORY_SEPARATOR.'mysql-ca.pem'))->toBeFalse();
    } finally {
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('accepts a readable certificate file path without printing the path', function () {
    $pem = mysql_ca_sample_pem();
    $token = 'ca-path-token-'.bin2hex(random_bytes(4));
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$token;
    mkdir($directory);
    $source = $directory.DIRECTORY_SEPARATOR.'existing.pem';
    file_put_contents($source, $pem);
    $output_directory = $directory.DIRECTORY_SEPARATOR.'out';
    mkdir($output_directory);

    try {
        $process = run_mysql_ca_entrypoint([
            'DB_SSL_CA' => str_replace('\\', '/', $source),
            'MYSQL_CA_DIRECTORY' => str_replace('\\', '/', $output_directory),
        ]);
        $combined = $process->getOutput().$process->getErrorOutput();

        expect($process->isSuccessful())->toBeTrue()
            ->and($combined)->toContain('Database CA certificate file is readable.')
            ->and($combined)->not->toContain($token)
            ->and(is_file($output_directory.DIRECTORY_SEPARATOR.'mysql-ca.pem'))->toBeFalse();
    } finally {
        if (is_file($source)) {
            unlink($source);
        }

        if (is_dir($output_directory)) {
            rmdir($output_directory);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});
