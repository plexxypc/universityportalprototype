<?php

declare(strict_types=1);

use App\Support\EnvironmentFileLoader;
use Illuminate\Foundation\Application;

/**
 * Warnings raised while the environment file is loaded.
 *
 * @return list<string>
 */
function environment_file_warnings(Application $app): array
{
    $warnings = [];

    set_error_handler(function (int $severity, string $message) use (&$warnings): bool {
        unset($severity);

        $warnings[] = $message;

        return true;
    });

    try {
        (new EnvironmentFileLoader)->bootstrap($app);
    } finally {
        restore_error_handler();
    }

    return $warnings;
}

/**
 * An application whose environment path is a temporary directory.
 */
function environment_file_app(string $directory, string $file_name): Application
{
    /** @var Application $app */
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('configurationIsCached')->andReturn(false);
    $app->shouldReceive('runningInConsole')->andReturn(false);
    $app->shouldReceive('environmentPath')->andReturn($directory);
    $app->shouldReceive('environmentFile')->andReturn($file_name);
    $app->shouldReceive('environmentFilePath')->andReturn($directory.DIRECTORY_SEPARATOR.$file_name);

    return $app;
}

it('does not read a missing environment file', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'portal-missing-env';

    if (! is_dir($directory)) {
        mkdir($directory);
    }

    $warnings = environment_file_warnings(environment_file_app($directory, '.env'));

    expect($warnings)->toBe([]);
});

it('loads an environment file when it exists', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'portal-present-env';
    $path = $directory.DIRECTORY_SEPARATOR.'.env';

    if (! is_dir($directory)) {
        mkdir($directory);
    }

    file_put_contents($path, "PORTAL_ENV_PROBE=loaded\n");

    try {
        $warnings = environment_file_warnings(environment_file_app($directory, '.env'));

        expect($warnings)->toBe([])
            ->and(getenv('PORTAL_ENV_PROBE'))->toBe('loaded');
    } finally {
        putenv('PORTAL_ENV_PROBE');
        unset($_ENV['PORTAL_ENV_PROBE'], $_SERVER['PORTAL_ENV_PROBE']);

        if (is_file($path)) {
            unlink($path);
        }
    }
});
