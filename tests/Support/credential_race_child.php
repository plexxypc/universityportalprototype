<?php

declare(strict_types=1);

use App\Models\Programme;
use App\Models\User;
use App\Services\CredentialService;
use App\Services\StudentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * One side of a credential or matric race.
 *
 * The parent starts two copies. Each waits for the go file, then writes
 * ok, code, and matric_no. Exception text is not written.
 * Arguments: ready file, go file, result file, mode (matric or resend), payload file.
 */
$ready_file = $argv[1] ?? '';
$go_file = $argv[2] ?? '';
$result_file = $argv[3] ?? '';
$mode = $argv[4] ?? '';
$payload_file = $argv[5] ?? '';

$database = getenv('DB_DATABASE');
$host = getenv('DB_HOST');

if ($database !== 'university_portal_testing' || $host !== '127.0.0.1') {
    credential_race_finish($result_file, false, 'failed', null);
    exit(0);
}

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$configured = config('database.connections.mysql.database');
$configured_host = config('database.connections.mysql.host');

if ($configured !== 'university_portal_testing' || $configured_host !== '127.0.0.1') {
    credential_race_finish($result_file, false, 'failed', null);
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
    credential_race_finish($result_file, false, 'timeout', null);
    exit(0);
}

$payload = credential_race_payload($payload_file);

if ($payload === null) {
    credential_race_finish($result_file, false, 'failed', null);
    exit(0);
}

$last_code = 'failed';

for ($attempt = 0; $attempt < 2; $attempt++) {
    try {
        if ($mode === 'matric') {
            $created = app(StudentService::class)->create(
                credential_race_actor($payload),
                credential_race_input($payload),
                credential_race_request(),
            );
            credential_race_finish($result_file, true, 'issued', $created->student->matric_no);

            exit(0);
        }

        if ($mode === 'resend') {
            $account = User::query()->find($payload['user_id'] ?? 0);

            if (! $account instanceof User) {
                credential_race_finish($result_file, false, 'failed', null);
                exit(0);
            }

            app(CredentialService::class)->resend(
                credential_race_actor($payload),
                $account,
                (string) ($payload['login_id'] ?? ''),
                credential_race_request(),
                false,
            );
            credential_race_finish($result_file, true, 'issued', (string) ($payload['login_id'] ?? ''));

            exit(0);
        }

        credential_race_finish($result_file, false, 'failed', null);
        exit(0);
    } catch (ValidationException $exception) {
        $messages = $exception->errors()['credentials'] ?? [];
        $message = is_array($messages) ? (string) ($messages[0] ?? '') : '';
        $code = $message === CredentialService::MESSAGE_COOLDOWN ? 'cooldown' : 'validation';
        credential_race_finish($result_file, false, $code, null);
        exit(0);
    } catch (QueryException $exception) {
        $driver_code = (int) ($exception->errorInfo[1] ?? 0);

        if (($driver_code === 1213 || $driver_code === 1205) && $attempt === 0) {
            $last_code = 'failed';

            continue;
        }

        $last_code = $driver_code === 1062 ? 'duplicate' : 'failed';
        break;
    } catch (Throwable) {
        $last_code = 'failed';
        break;
    }
}

credential_race_finish($result_file, false, $last_code, null);
exit(0);

/**
 * Write the only result this process may leave on disk.
 */
function credential_race_finish(string $path, bool $ok, string $code, ?string $matric_no): void
{
    if ($path === '') {
        return;
    }

    $allowed = ['issued', 'cooldown', 'validation', 'duplicate', 'failed', 'timeout'];

    if (! in_array($code, $allowed, true)) {
        $code = 'failed';
    }

    $json = json_encode([
        'ok' => $ok,
        'code' => $code,
        'matric_no' => $matric_no,
    ], JSON_THROW_ON_ERROR);

    file_put_contents($path, $json);
}

/**
 * @return array<string, mixed>|null
 */
function credential_race_payload(string $path): ?array
{
    if ($path === '' || ! is_file($path)) {
        return null;
    }

    $contents = file_get_contents($path);

    if (! is_string($contents)) {
        return null;
    }

    $decoded = json_decode($contents, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * The staff user committed by the parent.
 *
 * @param  array<string, mixed>  $payload
 */
function credential_race_actor(array $payload): User
{
    $actor = User::query()->find($payload['actor_id'] ?? 0);

    if (! $actor instanceof User) {
        throw new RuntimeException('actor missing');
    }

    return $actor;
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function credential_race_input(array $payload): array
{
    $programme = Programme::query()->find($payload['programme_id'] ?? 0);

    if (! $programme instanceof Programme) {
        throw new RuntimeException('programme missing');
    }

    return [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'other_names' => null,
        'email' => (string) ($payload['email'] ?? ''),
        'phone' => '08012345678',
        'gender' => 'Female',
        'date_of_birth' => '2004-05-01',
        'state_of_origin' => 'Lagos',
        'address' => '12 Garden Close',
        'programme_id' => $programme->id,
        'level' => 100,
        'entry_session_id' => null,
    ];
}

/**
 * A staff request with a fixed address.
 */
function credential_race_request(): Request
{
    return Request::create('/staff/students', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.20',
    ]);
}
