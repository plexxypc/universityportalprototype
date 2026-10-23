<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\GradingConfigService;
use App\Services\ResultService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * One side of a result race.
 *
 * The parent starts two copies. Each waits for the go file, then runs one
 * result action. Exception text is not written.
 * Arguments: ready file, go file, result file, mode, payload file.
 */
$ready_file = $argv[1] ?? '';
$go_file = $argv[2] ?? '';
$result_file = $argv[3] ?? '';
$mode = $argv[4] ?? '';
$payload_file = $argv[5] ?? '';

$database = getenv('DB_DATABASE');
$host = getenv('DB_HOST');

if ($database !== 'university_portal_testing' || $host !== '127.0.0.1') {
    result_race_child_finish($result_file, false, 'failed');
    exit(0);
}

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$configured = config('database.connections.mysql.database');
$configured_host = config('database.connections.mysql.host');

if ($configured !== 'university_portal_testing' || $configured_host !== '127.0.0.1') {
    result_race_child_finish($result_file, false, 'failed');
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
    result_race_child_finish($result_file, false, 'timeout');
    exit(0);
}

$contents = is_file($payload_file) ? file_get_contents($payload_file) : false;
$payload = is_string($contents) ? json_decode($contents, true) : null;

if (! is_array($payload)) {
    result_race_child_finish($result_file, false, 'failed');
    exit(0);
}

$user = User::query()->find($payload['user_id'] ?? 0);

if (! $user instanceof User) {
    result_race_child_finish($result_file, false, 'failed');
    exit(0);
}

$request = Request::create('/staff/results', 'POST');
$request->server->set('REMOTE_ADDR', '127.0.0.1');

try {
    result_race_child_act($mode, $user, $payload, $request);
    result_race_child_finish($result_file, true, 'ok');
} catch (ValidationException $exception) {
    result_race_child_finish($result_file, false, result_race_child_code((string) $exception->validator->errors()->first()));
} catch (QueryException $exception) {
    $driver = (int) ($exception->errorInfo[1] ?? 0);
    $code = $exception->getCode() === '40001' || $driver === 1213 ? 'deadlock' : 'failed';
    result_race_child_finish($result_file, false, $code);
} catch (Throwable) {
    result_race_child_finish($result_file, false, 'failed');
}

/**
 * Run one result action.
 *
 * @param  array<string, mixed>  $payload
 */
function result_race_child_act(string $mode, User $user, array $payload, Request $request): void
{
    $service = app(ResultService::class);
    $course_id = (int) ($payload['course_id'] ?? 0);
    $semester_id = (int) ($payload['semester_id'] ?? 0);

    if ($mode === 'approve') {
        $service->approve($user, $course_id, $semester_id, $request);

        return;
    }

    if ($mode === 'publish') {
        $service->publish($user, $semester_id, [$course_id], $request);

        return;
    }

    if ($mode === 'submit') {
        $service->submit($user, $course_id, $semester_id, $request);

        return;
    }

    if ($mode === 'amend') {
        $service->amend(
            $user,
            (int) ($payload['result_id'] ?? 0),
            result_race_child_scores($payload['amend_scores'] ?? []),
            'Race review',
            (string) ($payload['version'] ?? ''),
            $request,
        );

        return;
    }

    if ($mode === 'save_a' || $mode === 'save_b') {
        $service->saveDraft($user, $course_id, $semester_id, [[
            'student_id' => (int) ($payload['student_id'] ?? 0),
            'scores' => result_race_child_scores($payload[$mode] ?? []),
            'version' => $payload['version'] ?? null,
        ]], $request);

        return;
    }

    if ($mode === 'scheme') {
        $input = app(GradingConfigService::class)->defaultInput();
        $input['name'] = 'Nigerian 5-point race';
        app(GradingConfigService::class)->saveVersion($user, $input, $request);

        return;
    }

    throw new RuntimeException('Unknown result race mode.');
}

/**
 * @return array<int, string>
 */
function result_race_child_scores(mixed $scores): array
{
    if (! is_array($scores)) {
        return [];
    }

    $clean = [];

    foreach ($scores as $component_id => $score) {
        if ((is_int($component_id) || (is_string($component_id) && ctype_digit($component_id))) && is_string($score)) {
            $clean[(int) $component_id] = $score;
        }
    }

    return $clean;
}

/**
 * Map a validation message to a short code.
 */
function result_race_child_code(string $message): string
{
    return match ($message) {
        ResultService::MESSAGE_VERSION => 'version',
        ResultService::MESSAGE_STATUS => 'status',
        ResultService::MESSAGE_TRANSITION => 'transition',
        ResultService::MESSAGE_COMPONENT => 'component',
        ResultService::MESSAGE_DISAGREE, ResultService::MESSAGE_SCORE => 'refused',
        default => 'failed',
    };
}

/**
 * Write the child outcome. The message stays out of the file.
 */
function result_race_child_finish(string $result_file, bool $ok, string $code): void
{
    if ($result_file === '') {
        return;
    }

    file_put_contents($result_file, json_encode([
        'ok' => $ok,
        'code' => $code,
    ], JSON_THROW_ON_ERROR));
}
