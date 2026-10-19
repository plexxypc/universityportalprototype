<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

/**
 * One side of a registration submit race.
 *
 * The parent starts two copies. Each waits for the go file, then submits
 * the same courses. Exception text is not written.
 * Arguments: ready file, go file, result file, payload file.
 */
$ready_file = $argv[1] ?? '';
$go_file = $argv[2] ?? '';
$result_file = $argv[3] ?? '';
$payload_file = $argv[4] ?? '';

$database = getenv('DB_DATABASE');
$host = getenv('DB_HOST');

if ($database !== 'university_portal_testing' || $host !== '127.0.0.1') {
    registration_race_child_finish($result_file, false, 'failed', registration_race_child_failure(null));
    exit(0);
}

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$configured = config('database.connections.mysql.database');
$configured_host = config('database.connections.mysql.host');

if ($configured !== 'university_portal_testing' || $configured_host !== '127.0.0.1') {
    registration_race_child_finish($result_file, false, 'failed', registration_race_child_failure(null));
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
    registration_race_child_finish($result_file, false, 'timeout', registration_race_child_failure(null));
    exit(0);
}

$contents = is_file($payload_file) ? file_get_contents($payload_file) : false;
$payload = is_string($contents) ? json_decode($contents, true) : null;

if (! is_array($payload)) {
    registration_race_child_finish($result_file, false, 'failed', registration_race_child_failure(null));
    exit(0);
}

$user = User::query()->find($payload['user_id'] ?? 0);
$course_ids = [];

foreach ($payload['course_ids'] ?? [] as $course_id) {
    if (is_int($course_id) || (is_string($course_id) && ctype_digit($course_id))) {
        $course_ids[] = (int) $course_id;
    }
}

if (! $user instanceof User) {
    registration_race_child_finish($result_file, false, 'failed', registration_race_child_failure(null));
    exit(0);
}

$request = Request::create('/student/course-registration', 'POST');
$request->server->set('REMOTE_ADDR', '127.0.0.1');
$failure = registration_race_child_failure(null);

$action = is_string($payload['action'] ?? null) ? $payload['action'] : 'submit';

for ($attempt = 0; $attempt < 2; $attempt++) {
    try {
        if ($action === 'approve') {
            $version = is_string($payload['version'] ?? null) ? $payload['version'] : '';
            app(RegistrationService::class)->approve($user, (int) ($payload['registration_id'] ?? 0), $version, $request);
            registration_race_child_finish($result_file, true, 'submitted', $failure);
            exit(0);
        }

        if ($course_ids === []) {
            registration_race_child_finish($result_file, false, 'failed', $failure);
            exit(0);
        }

        app(RegistrationService::class)->submit($user, $course_ids, $request);
        registration_race_child_finish($result_file, true, 'submitted', $failure);
        exit(0);
    } catch (Throwable $exception) {
        $failure = registration_race_child_failure($exception);
        $driver_code = (int) $failure['error_code'];

        if (($driver_code === 1213 || $driver_code === 1205) && $attempt === 0) {
            continue;
        }

        break;
    }
}

registration_race_child_finish($result_file, false, 'failed', $failure);
exit(0);

/**
 * Class name and SQLSTATE or driver code. Message text and row data stay out.
 *
 * @return array{exception: string, sqlstate: string, error_code: string}
 */
function registration_race_child_failure(?Throwable $exception): array
{
    if (! $exception instanceof Throwable) {
        return ['exception' => '', 'sqlstate' => '', 'error_code' => ''];
    }

    $class = $exception::class;
    $exception_name = preg_match('/^[A-Za-z0-9_\\\\]+$/', $class) === 1 ? $class : 'Exception';
    $sqlstate = '';
    $error_code = '';

    if ($exception instanceof QueryException) {
        $state = $exception->errorInfo[0] ?? null;
        $code = $exception->errorInfo[1] ?? null;

        if (is_string($state) && preg_match('/^[0-9A-Z]{5}$/', $state) === 1) {
            $sqlstate = $state;
        }

        if (is_int($code) || (is_string($code) && preg_match('/^[0-9]{1,6}$/', $code) === 1)) {
            $error_code = (string) (int) $code;
        }
    }

    return [
        'exception' => $exception_name,
        'sqlstate' => $sqlstate,
        'error_code' => $error_code,
    ];
}

/**
 * Write the only result this process may leave on disk.
 *
 * @param  array{exception: string, sqlstate: string, error_code: string}  $failure
 */
function registration_race_child_finish(string $path, bool $ok, string $code, array $failure): void
{
    if ($path === '') {
        return;
    }

    if (! in_array($code, ['submitted', 'failed', 'timeout'], true)) {
        $code = 'failed';
    }

    file_put_contents($path, json_encode([
        'ok' => $ok,
        'code' => $code,
        'exception' => $failure['exception'],
        'sqlstate' => $failure['sqlstate'],
        'error_code' => $failure['error_code'],
    ], JSON_THROW_ON_ERROR));
}
