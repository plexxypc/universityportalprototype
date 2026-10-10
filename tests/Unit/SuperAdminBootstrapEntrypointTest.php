<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Shell that can run docker/entrypoint.sh.
 *
 * @return array{binary: string, path: string}
 */
function bootstrap_entrypoint_shell(): array
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
 * A php stand-in that records its arguments and exits with the requested code.
 *
 * @return array{directory: string, log: string}
 */
function bootstrap_fake_php(int $exit_code): array
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'super-admin-php-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $log = $directory.DIRECTORY_SEPARATOR.'args.log';
    $script = $directory.DIRECTORY_SEPARATOR.'php';

    file_put_contents($script, <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$PHP_ARGS_LOG"
if [ -n "${PHP_FAKE_OUTPUT:-}" ]; then
    printf '%s\n' "$PHP_FAKE_OUTPUT"
fi
if [ "$1" = "artisan" ] && [ "$2" = "create-super-admin" ]; then
    exit "$PHP_EXIT_CODE"
fi
exit 0
SH);

    $shell = bootstrap_entrypoint_shell();
    $chmod = new Process([$shell['binary'], '-c', 'chmod +x '.escapeshellarg($script)]);
    $chmod->run();

    return [
        'directory' => $directory,
        'log' => $log,
        'exit_code' => (string) $exit_code,
    ];
}

/**
 * Run the entrypoint bootstrap hook.
 *
 * @param  array<string, string>  $environment
 */
function run_super_admin_bootstrap_hook(array $environment): Process
{
    $shell = bootstrap_entrypoint_shell();
    $root = dirname(__DIR__, 2);
    $process = new Process(
        [$shell['binary'], $root.DIRECTORY_SEPARATOR.'docker'.DIRECTORY_SEPARATOR.'entrypoint.sh', '--bootstrap-super-admin'],
        $root,
        $environment,
    );
    $process->setTimeout(30);
    $process->run();

    return $process;
}

it('parses the entrypoint with sh -n', function () {
    $shell = bootstrap_entrypoint_shell();
    $root = dirname(__DIR__, 2);
    $process = new Process([
        $shell['binary'],
        '-n',
        $root.DIRECTORY_SEPARATOR.'docker'.DIRECTORY_SEPARATOR.'entrypoint.sh',
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue();
});

it('keeps booting when create-super-admin fails and does not print the secrets', function () {
    $fake = bootstrap_fake_php(1);
    $email = 'bootstrap-secret-email@example.test';
    $hash = 'bootstrap-secret-hash-value';

    try {
        $process = run_super_admin_bootstrap_hook([
            'PATH' => $fake['directory'].PATH_SEPARATOR.bootstrap_entrypoint_shell()['path'],
            'APP_ENV' => 'production',
            'BOOTSTRAP_SUPER_ADMIN_EMAIL' => $email,
            'BOOTSTRAP_SUPER_ADMIN_PASSWORD_HASH' => $hash,
            'PHP_ARGS_LOG' => $fake['log'],
            'PHP_EXIT_CODE' => $fake['exit_code'],
        ]);

        $output = $process->getOutput().$process->getErrorOutput();

        expect($process->getExitCode())->toBe(0)
            ->and(trim($output))->toBe('Super Admin bootstrap failed: unexpected_error exit=1')
            ->and(substr_count($output, 'Super Admin bootstrap failed:'))->toBe(1)
            ->and($output)->not->toContain($email)
            ->and($output)->not->toContain($hash)
            ->and((string) file_get_contents($fake['log']))->toContain('artisan create-super-admin --no-interaction')
            ->and((string) file_get_contents($fake['log']))->not->toContain($hash);
    } finally {
        if (is_file($fake['log'])) {
            unlink($fake['log']);
        }

        $script = $fake['directory'].DIRECTORY_SEPARATOR.'php';

        if (is_file($script)) {
            unlink($script);
        }

        if (is_dir($fake['directory'])) {
            rmdir($fake['directory']);
        }
    }
});

it('drops a stack trace that contains the email and keeps one reason line', function () {
    $fake = bootstrap_fake_php(1);
    $email = 'bootstrap-secret-email@example.test';
    $hash = 'bootstrap-secret-hash-value';

    try {
        $process = run_super_admin_bootstrap_hook([
            'PATH' => $fake['directory'].PATH_SEPARATOR.bootstrap_entrypoint_shell()['path'],
            'APP_ENV' => 'production',
            'BOOTSTRAP_SUPER_ADMIN_EMAIL' => $email,
            'BOOTSTRAP_SUPER_ADMIN_PASSWORD_HASH' => $hash,
            'PHP_ARGS_LOG' => $fake['log'],
            'PHP_EXIT_CODE' => $fake['exit_code'],
            'PHP_FAKE_OUTPUT' => 'SQLSTATE[42S02] '.$email.' '.$hash."\nStack trace:\n#0 SuperAdminService.php",
        ]);

        $output = $process->getOutput().$process->getErrorOutput();

        expect($process->getExitCode())->toBe(0)
            ->and(trim($output))->toBe('Super Admin bootstrap failed: unexpected_error exit=1')
            ->and(str_contains($output, $email))->toBeFalse()
            ->and(str_contains($output, $hash))->toBeFalse()
            ->and(str_contains($output, 'Stack trace'))->toBeFalse();
    } finally {
        if (is_file($fake['log'])) {
            unlink($fake['log']);
        }

        $script = $fake['directory'].DIRECTORY_SEPARATOR.'php';

        if (is_file($script)) {
            unlink($script);
        }

        if (is_dir($fake['directory'])) {
            rmdir($fake['directory']);
        }
    }
});

it('forwards one safe bootstrap failure line', function () {
    $fake = bootstrap_fake_php(1);
    $email = 'bootstrap-secret-email@example.test';
    $hash = 'bootstrap-secret-hash-value';
    $line = 'Super Admin bootstrap failed: tables_missing exit=1. run migrations first.';

    try {
        $process = run_super_admin_bootstrap_hook([
            'PATH' => $fake['directory'].PATH_SEPARATOR.bootstrap_entrypoint_shell()['path'],
            'APP_ENV' => 'production',
            'BOOTSTRAP_SUPER_ADMIN_EMAIL' => $email,
            'BOOTSTRAP_SUPER_ADMIN_PASSWORD_HASH' => $hash,
            'PHP_ARGS_LOG' => $fake['log'],
            'PHP_EXIT_CODE' => $fake['exit_code'],
            'PHP_FAKE_OUTPUT' => $line,
        ]);

        $output = $process->getOutput().$process->getErrorOutput();

        expect($process->getExitCode())->toBe(0)
            ->and(trim($output))->toBe($line)
            ->and(str_contains($output, $email))->toBeFalse()
            ->and(str_contains($output, $hash))->toBeFalse();
    } finally {
        if (is_file($fake['log'])) {
            unlink($fake['log']);
        }

        $script = $fake['directory'].DIRECTORY_SEPARATOR.'php';

        if (is_file($script)) {
            unlink($script);
        }

        if (is_dir($fake['directory'])) {
            rmdir($fake['directory']);
        }
    }
});

it('forwards a successful bootstrap message', function () {
    $fake = bootstrap_fake_php(0);
    $email = 'bootstrap-secret-email@example.test';
    $hash = 'bootstrap-secret-hash-value';

    try {
        $process = run_super_admin_bootstrap_hook([
            'PATH' => $fake['directory'].PATH_SEPARATOR.bootstrap_entrypoint_shell()['path'],
            'APP_ENV' => 'production',
            'BOOTSTRAP_SUPER_ADMIN_EMAIL' => $email,
            'BOOTSTRAP_SUPER_ADMIN_PASSWORD_HASH' => $hash,
            'PHP_ARGS_LOG' => $fake['log'],
            'PHP_EXIT_CODE' => $fake['exit_code'],
            'PHP_FAKE_OUTPUT' => 'Super Admin created.',
        ]);

        $output = $process->getOutput().$process->getErrorOutput();

        expect($process->getExitCode())->toBe(0)
            ->and($output)->toContain('Super Admin created.')
            ->and(str_contains($output, $email))->toBeFalse()
            ->and(str_contains($output, $hash))->toBeFalse();
    } finally {
        if (is_file($fake['log'])) {
            unlink($fake['log']);
        }

        $script = $fake['directory'].DIRECTORY_SEPARATOR.'php';

        if (is_file($script)) {
            unlink($script);
        }

        if (is_dir($fake['directory'])) {
            rmdir($fake['directory']);
        }
    }
});

it('skips the bootstrap outside production', function () {
    $fake = bootstrap_fake_php(99);

    try {
        $process = run_super_admin_bootstrap_hook([
            'PATH' => $fake['directory'].PATH_SEPARATOR.bootstrap_entrypoint_shell()['path'],
            'APP_ENV' => 'local',
            'BOOTSTRAP_SUPER_ADMIN_EMAIL' => 'bootstrap-secret-email@example.test',
            'BOOTSTRAP_SUPER_ADMIN_PASSWORD_HASH' => 'bootstrap-secret-hash-value',
            'PHP_ARGS_LOG' => $fake['log'],
            'PHP_EXIT_CODE' => '99',
        ]);

        expect($process->getExitCode())->toBe(0)
            ->and(is_file($fake['log']))->toBeFalse();
    } finally {
        if (is_file($fake['log'])) {
            unlink($fake['log']);
        }

        $script = $fake['directory'].DIRECTORY_SEPARATOR.'php';

        if (is_file($script)) {
            unlink($script);
        }

        if (is_dir($fake['directory'])) {
            rmdir($fake['directory']);
        }
    }
});
