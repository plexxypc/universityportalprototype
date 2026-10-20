<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * One side of a payment race.
 *
 * Arguments: ready file, go file, result file, mode, payload file.
 * The child writes ok and a short code. It does not write exception text.
 */
$ready_file = $argv[1] ?? '';
$go_file = $argv[2] ?? '';
$result_file = $argv[3] ?? '';
$mode = $argv[4] ?? '';
$payload_file = $argv[5] ?? '';

$database = getenv('DB_DATABASE');
$host = getenv('DB_HOST');

if ($database !== 'university_portal_testing' || $host !== '127.0.0.1') {
    payment_race_finish($result_file, false, 'failed');
    exit(0);
}

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$configured = config('database.connections.mysql.database');
$configured_host = config('database.connections.mysql.host');

if ($configured !== 'university_portal_testing' || $configured_host !== '127.0.0.1') {
    payment_race_finish($result_file, false, 'failed');
    exit(0);
}

config([
    'mail.default' => 'array',
    'queue.default' => 'sync',
    'cache.default' => 'database',
]);

if ($ready_file !== '') {
    file_put_contents($ready_file, '1');
}

$deadline = time() + 20;

while ($go_file !== '' && ! is_file($go_file) && time() < $deadline) {
    usleep(20000);
}

if ($go_file === '' || ! is_file($go_file)) {
    payment_race_finish($result_file, false, 'timeout');
    exit(0);
}

$decoded = is_file($payload_file) ? json_decode((string) file_get_contents($payload_file), true) : null;

if (! is_array($decoded)) {
    payment_race_finish($result_file, false, 'failed');
    exit(0);
}

try {
    if ($mode === 'callback' || $mode === 'notification') {
        $reference = is_string($decoded['reference'] ?? null) ? $decoded['reference'] : '';
        app(PaymentService::class)->verifyAndSettle($reference, $mode);
        payment_race_finish($result_file, true, 'ok');
        exit(0);
    }

    if ($mode === 'poll') {
        $app->make(Kernel::class)->call('payments:poll-pending');
        payment_race_finish($result_file, true, 'ok');
        exit(0);
    }

    if ($mode === 'initiate') {
        $user = User::query()->find($decoded['user_id'] ?? 0);
        $invoice_id = (int) ($decoded['invoice_id'] ?? 0);

        if (! $user instanceof User) {
            payment_race_finish($result_file, false, 'failed');
            exit(0);
        }

        app(PaymentService::class)->initiate($user, $invoice_id, '2000.00', Request::create('/student/fees/'.$invoice_id.'/pay', 'POST'));
        payment_race_finish($result_file, true, 'ok');
        exit(0);
    }

    if ($mode === 'adjust') {
        $actor = User::query()->find($decoded['actor_id'] ?? 0);
        $invoice_id = (int) ($decoded['invoice_id'] ?? 0);

        if (! $actor instanceof User) {
            payment_race_finish($result_file, false, 'failed');
            exit(0);
        }

        app(InvoiceService::class)->addAdjustment(
            $actor,
            $invoice_id,
            'Scholarship',
            '1000.00',
            'Recorded during settlement',
            Request::create('/staff/invoices', 'POST'),
        );
        payment_race_finish($result_file, true, 'ok');
        exit(0);
    }

    payment_race_finish($result_file, false, 'failed');
} catch (ValidationException) {
    payment_race_finish($result_file, true, 'refused');
} catch (Throwable) {
    payment_race_finish($result_file, false, 'failed');
}

/**
 * Write the result and stop.
 */
function payment_race_finish(string $path, bool $ok, string $code): void
{
    if ($path === '') {
        return;
    }

    file_put_contents($path, json_encode([
        'ok' => $ok,
        'code' => $code,
    ], JSON_THROW_ON_ERROR));
}
