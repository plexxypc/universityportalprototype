<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * Run queue:work and pause inside the mailer until a release file appears.
 *
 * The signal tests start this file with php. It is not application code.
 * Arguments are the ready-file path and the release-file path.
 */
define('LARAVEL_START', microtime(true));

$ready_file = $argv[1] ?? '';
$release_file = $argv[2] ?? '';

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';

$app->booted(function () use ($ready_file, $release_file): void {
    config(['queue.default' => 'database']);

    Event::listen(function (MessageSending $event) use ($ready_file, $release_file): void {
        unset($event);

        if ($ready_file !== '') {
            file_put_contents($ready_file, '1');
        }

        $deadline = time() + 25;

        while (time() < $deadline) {
            if ($release_file !== '' && is_file($release_file)) {
                return;
            }

            usleep(50000);
        }
    });
});

$status = $app->handleCommand(new ArgvInput([
    'artisan',
    'queue:work',
    '--sleep=1',
    '--timeout=30',
    '--tries=1',
    '--memory=128',
    '--no-interaction',
]));

exit($status);
