<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\InvoiceService;
use App\Services\StudentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * One side of an invoice race.
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
    invoice_race_finish($result_file, false, 'failed');
    exit(0);
}

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$configured = config('database.connections.mysql.database');
$configured_host = config('database.connections.mysql.host');

if ($configured !== 'university_portal_testing' || $configured_host !== '127.0.0.1') {
    invoice_race_finish($result_file, false, 'failed');
    exit(0);
}

config([
    'mail.default' => 'array',
    'queue.default' => 'sync',
]);

if ($ready_file !== '') {
    file_put_contents($ready_file, '1');
}

$deadline = time() + 20;

while ($go_file !== '' && ! is_file($go_file) && time() < $deadline) {
    usleep(20000);
}

if ($go_file === '' || ! is_file($go_file)) {
    invoice_race_finish($result_file, false, 'timeout');
    exit(0);
}

$decoded = is_file($payload_file) ? json_decode((string) file_get_contents($payload_file), true) : null;

if (! is_array($decoded)) {
    invoice_race_finish($result_file, false, 'failed');
    exit(0);
}

try {
    if ($mode === 'confirm') {
        $actor = User::query()->find($decoded['bursar_id'] ?? 0);

        if (! $actor instanceof User) {
            invoice_race_finish($result_file, false, 'failed');
            exit(0);
        }

        app(InvoiceService::class)->confirmChunk(
            $actor,
            (int) ($decoded['programme_id'] ?? 0),
            (int) ($decoded['level'] ?? 0),
            (int) ($decoded['session_id'] ?? 0),
            (int) ($decoded['after_student_id'] ?? 0),
            Request::create('/staff/invoices', 'POST'),
        );
        invoice_race_finish($result_file, true, 'ok');
        exit(0);
    }

    if ($mode === 'create') {
        $actor = User::query()->find($decoded['registrar_id'] ?? 0);

        if (! $actor instanceof User) {
            invoice_race_finish($result_file, false, 'failed');
            exit(0);
        }

        app(StudentService::class)->create($actor, [
            'first_name' => 'Ada',
            'last_name' => 'Race',
            'email' => is_string($decoded['email'] ?? null) ? $decoded['email'] : '',
            'phone' => '08012345678',
            'gender' => 'Female',
            'date_of_birth' => '2004-05-01',
            'state_of_origin' => 'Lagos',
            'address' => '12 Garden Close',
            'programme_id' => (int) ($decoded['programme_id'] ?? 0),
            'level' => (int) ($decoded['level'] ?? 100),
            'entry_session_id' => (int) ($decoded['session_id'] ?? 0),
        ], Request::create('/staff/students', 'POST'), false);
        invoice_race_finish($result_file, true, 'ok');
        exit(0);
    }

    invoice_race_finish($result_file, false, 'failed');
} catch (ValidationException) {
    invoice_race_finish($result_file, true, 'refused');
} catch (Throwable) {
    invoice_race_finish($result_file, false, 'failed');
}

/**
 * Write the result and stop.
 */
function invoice_race_finish(string $path, bool $ok, string $code): void
{
    if ($path === '') {
        return;
    }

    file_put_contents($path, json_encode([
        'ok' => $ok,
        'code' => $code,
    ], JSON_THROW_ON_ERROR));
}
