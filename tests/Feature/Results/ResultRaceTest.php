<?php

declare(strict_types=1);

use App\Enums\ResultStatus;
use App\Models\AuditLog;
use App\Models\GradingScheme;
use App\Models\Result;
use App\Services\ResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ResultFixture;

uses(RefreshDatabase::class);

/**
 * True when this process can start and signal another PHP process.
 */
function result_race_available(): bool
{
    return function_exists('posix_kill')
        && function_exists('proc_open')
        && extension_loaded('pcntl')
        && defined('SIGKILL');
}

/**
 * @return array<string, string>
 */
function result_race_env(): array
{
    $env = [];
    $inherited = getenv();

    if (is_array($inherited)) {
        foreach ($inherited as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }
    }

    $env['DB_CONNECTION'] = 'mysql';
    $env['DB_HOST'] = '127.0.0.1';
    $env['DB_PORT'] = '3306';
    $env['DB_DATABASE'] = 'university_portal_testing';
    $env['DB_USERNAME'] = $env['DB_USERNAME'] ?? 'portal';
    $env['DB_PASSWORD'] = $env['DB_PASSWORD'] ?? 'portal';
    $env['DB_URL'] = '';
    $env['APP_ENV'] = 'testing';
    $env['APP_DEBUG'] = 'false';
    $env['APP_KEY'] = $env['APP_KEY'] ?? 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=';
    $env['MAIL_MAILER'] = 'array';
    $env['QUEUE_CONNECTION'] = 'sync';
    $env['CACHE_STORE'] = 'array';

    return $env;
}

/**
 * Leave the testing database so child processes can read committed rows.
 */
function result_race_release(): bool
{
    $connection = DB::connection();
    $wrapped = $connection->transactionLevel() > 0;

    if ($wrapped) {
        $connection->rollBack();
    }

    return $wrapped;
}

/**
 * Remove rows these races commit outside RefreshDatabase.
 */
function result_race_cleanup(): void
{
    DB::table('result_scores')->delete();
    DB::table('results')->delete();
    DB::table('course_registration_items')->delete();
    DB::table('course_registrations')->delete();
    DB::table('course_assignments')->delete();
    DB::table('course_prerequisites')->delete();
    DB::table('academic_standing_thresholds')->delete();
    DB::table('classification_bands')->delete();
    DB::table('grade_bands')->delete();
    DB::table('assessment_components')->delete();
    DB::table('grading_schemes')->delete();
    DB::table('email_outbox')->delete();
    DB::table('notifications')->delete();
    DB::table('audit_logs')->delete();
    DB::table('staff')->delete();
    DB::table('students')->delete();
    DB::table('courses')->delete();
    DB::table('role_assignments')->delete();
    DB::table('programmes')->delete();
    DB::table('departments')->delete();
    DB::table('faculties')->delete();
    DB::table('semesters')->delete();
    DB::table('academic_sessions')->delete();
    DB::table('users')->delete();
}

function result_race_restore(bool $wrapped): void
{
    result_race_cleanup();

    if ($wrapped && DB::connection()->transactionLevel() === 0) {
        DB::connection()->beginTransaction();
    }
}

/**
 * @param  list<array{mode: string, user_id: int}>  $sides
 * @param  array<string, mixed>  $payload
 * @return list<string>
 */
function result_race_run(array $sides, array $payload): array
{
    $go = tempnam(sys_get_temp_dir(), 'res-go');
    expect($go)->toBeString();

    if (is_file($go)) {
        unlink($go);
    }

    $children = [];
    $files = [(string) $go];
    $results = [];

    foreach ($sides as $side) {
        $ready = tempnam(sys_get_temp_dir(), 'res-ready');
        $result = tempnam(sys_get_temp_dir(), 'res-result');
        $payload_file = tempnam(sys_get_temp_dir(), 'res-payload');
        expect($ready)->toBeString()->and($result)->toBeString()->and($payload_file)->toBeString();

        if (is_file($ready)) {
            unlink($ready);
        }

        if (is_file($result)) {
            unlink($result);
        }

        $body = $payload;
        $body['user_id'] = $side['user_id'];
        file_put_contents((string) $payload_file, json_encode($body, JSON_THROW_ON_ERROR));
        $files[] = (string) $ready;
        $files[] = (string) $result;
        $files[] = (string) $payload_file;
        $results[] = (string) $result;
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/result_race_child.php'), (string) $ready, (string) $go, (string) $result, $side['mode'], (string) $payload_file],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            base_path(),
            result_race_env(),
        );
        expect(is_resource($process))->toBeTrue();

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $status = proc_get_status($process);
        $children[] = ['process' => $process, 'pid' => (int) $status['pid']];
    }

    $ready_deadline = time() + 60;

    while (time() < $ready_deadline) {
        $ready = true;

        foreach ($files as $file) {
            if (str_contains(basename($file), 'res-ready') && ! is_file($file)) {
                $ready = false;
            }
        }

        if ($ready) {
            break;
        }

        usleep(20000);
    }

    file_put_contents((string) $go, '1');
    $wait_deadline = time() + 60;

    while (time() < $wait_deadline) {
        $done = true;

        foreach ($results as $result) {
            if (! is_file($result)) {
                $done = false;
            }
        }

        if ($done) {
            break;
        }

        usleep(20000);
    }

    $codes = [];

    foreach ($results as $result) {
        $decoded = is_file($result) ? json_decode((string) file_get_contents($result), true) : null;
        $codes[] = is_array($decoded) && is_string($decoded['code'] ?? null) ? $decoded['code'] : 'timeout';
    }

    foreach ($children as $child) {
        $running = proc_get_status($child['process']);

        if ($running['running'] && function_exists('posix_kill')) {
            posix_kill($child['pid'], SIGKILL);
        }

        proc_close($child['process']);
    }

    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    return $codes;
}

/**
 * @return array<string, mixed>
 */
function result_race_payload(ResultFixture $fixture, Result $result): array
{
    return [
        'course_id' => $fixture->course->id,
        'semester_id' => $fixture->semester->id,
        'student_id' => $fixture->student->id,
        'result_id' => $result->id,
        'version' => $result->updated_at?->format('Y-m-d H:i:s'),
        'save_a' => [
            (string) $fixture->ca->id => '20.00',
            (string) $fixture->exam->id => '40.00',
        ],
        'save_b' => [
            (string) $fixture->ca->id => '20.00',
            (string) $fixture->exam->id => '45.00',
        ],
        'amend_scores' => [
            (string) $fixture->ca->id => '20.00',
            (string) $fixture->exam->id => '40.00',
        ],
    ];
}

/**
 * A submitted result the lecturer entered.
 */
function result_race_submitted(): ResultFixture
{
    $fixture = ResultFixture::make();
    result_workflow_draft($fixture);
    app(ResultService::class)->submit(
        $fixture->lecturer,
        $fixture->course->id,
        $fixture->semester->id,
        result_draft_request(),
    );

    return $fixture;
}

it('lets one approver win when two approve the same result', function () {
    if (! result_race_available()) {
        test()->markTestSkipped('pcntl and posix are required');
    }

    $wrapped = result_race_release();

    try {
        foreach ([1, 2, 3, 4, 5] as $round) {
            $fixture = result_race_submitted();
            $result = Result::query()->firstOrFail();
            $codes = result_race_run([
                ['mode' => 'approve', 'user_id' => $fixture->examOfficer->id],
                ['mode' => 'approve', 'user_id' => $fixture->departmentOfficer->id],
            ], result_race_payload($fixture, $result));
            sort($codes);

            expect($codes)->toBe(['ok', 'ok'])
                ->and($result->refresh()->status)->toBe(ResultStatus::Approved)
                ->and(AuditLog::query()->where('action', ResultService::ACTION_APPROVED)->count())->toBe(1);

            result_race_cleanup();
            unset($round);
        }
    } finally {
        result_race_restore($wrapped);
    }
});

it('publishes once when publish and amend run together', function () {
    if (! result_race_available()) {
        test()->markTestSkipped('pcntl and posix are required');
    }

    $wrapped = result_race_release();

    try {
        foreach ([1, 2, 3, 4, 5] as $round) {
            $fixture = result_race_submitted();
            app(ResultService::class)->approve(
                $fixture->examOfficer,
                $fixture->course->id,
                $fixture->semester->id,
                result_draft_request(),
            );
            $result = Result::query()->firstOrFail();
            $codes = result_race_run([
                ['mode' => 'publish', 'user_id' => $fixture->examOfficer->id],
                ['mode' => 'amend', 'user_id' => $fixture->superAdmin->id],
            ], result_race_payload($fixture, $result));
            sort($codes);

            expect($codes[0])->toBe('ok')
                ->and($codes[1])->toBeIn(['transition', 'version'])
                ->and($result->refresh()->status)->toBe(ResultStatus::Published)
                ->and((string) $result->total)->toBe('70.00')
                ->and(AuditLog::query()->where('action', ResultService::ACTION_PUBLISHED)->count())->toBe(1)
                ->and(AuditLog::query()->where('action', ResultService::ACTION_AMENDED)->count())->toBe(0);

            result_race_cleanup();
            unset($round);
        }
    } finally {
        result_race_restore($wrapped);
    }
});

it('submits once when a save and a submit run together', function () {
    if (! result_race_available()) {
        test()->markTestSkipped('pcntl and posix are required');
    }

    $wrapped = result_race_release();

    try {
        foreach ([1, 2, 3, 4, 5] as $round) {
            $fixture = ResultFixture::make();
            result_workflow_draft($fixture);
            $result = Result::query()->firstOrFail();
            $codes = result_race_run([
                ['mode' => 'save_a', 'user_id' => $fixture->lecturer->id],
                ['mode' => 'submit', 'user_id' => $fixture->lecturer->id],
            ], result_race_payload($fixture, $result));
            sort($codes);
            $result->refresh();

            expect($codes)->toBeIn([['ok', 'ok'], ['ok', 'status'], ['ok', 'version']])
                ->and($result->status)->toBe(ResultStatus::Submitted)
                ->and((string) $result->total)->toBeIn(['60.00', '70.00'])
                ->and(AuditLog::query()->where('action', ResultService::ACTION_SUBMITTED)->count())->toBe(1);

            result_race_cleanup();
            unset($round);
        }
    } finally {
        result_race_restore($wrapped);
    }
});

it('keeps one save when two drafts share a version token', function () {
    if (! result_race_available()) {
        test()->markTestSkipped('pcntl and posix are required');
    }

    $wrapped = result_race_release();

    try {
        foreach ([1, 2, 3, 4, 5] as $round) {
            $fixture = ResultFixture::make();
            result_workflow_draft($fixture);
            $result = Result::query()->firstOrFail();
            $before = AuditLog::query()->where('action', ResultService::ACTION_CHANGED)->count();
            $codes = result_race_run([
                ['mode' => 'save_a', 'user_id' => $fixture->lecturer->id],
                ['mode' => 'save_b', 'user_id' => $fixture->lecturer->id],
            ], result_race_payload($fixture, $result));
            sort($codes);

            expect($codes)->toBe(['ok', 'version'])
                ->and((string) $result->refresh()->total)->toBeIn(['60.00', '65.00'])
                ->and(AuditLog::query()->where('action', ResultService::ACTION_CHANGED)->count())->toBe($before + 1);

            result_race_cleanup();
            unset($round);
        }
    } finally {
        result_race_restore($wrapped);
    }
});

it('keeps one scheme when a draft save and a scheme change run together', function () {
    if (! result_race_available()) {
        test()->markTestSkipped('pcntl and posix are required');
    }

    $wrapped = result_race_release();

    try {
        foreach ([1, 2, 3, 4, 5] as $round) {
            $fixture = ResultFixture::make();
            result_workflow_draft($fixture);
            $result = Result::query()->firstOrFail();
            $codes = result_race_run([
                ['mode' => 'save_b', 'user_id' => $fixture->lecturer->id],
                ['mode' => 'scheme', 'user_id' => $fixture->superAdmin->id],
            ], result_race_payload($fixture, $result));
            $result->refresh();
            $active = GradingScheme::query()->where('is_active', true)->first();

            expect($codes)->each(fn ($code) => $code->not->toBeIn(['failed', 'timeout', 'deadlock']))
                ->and(GradingScheme::query()->where('is_active', true)->count())->toBe(1)
                ->and($result->status)->toBe(ResultStatus::Draft)
                ->and($active)->not->toBeNull()
                ->and((int) $result->grading_scheme_id)->toBe((int) $active?->id)
                ->and((int) $result->scheme_version)->toBe((int) $active?->version)
                ->and((string) $result->total)->toBeIn(['65.00', '70.00']);

            result_race_cleanup();
            unset($round);
        }
    } finally {
        result_race_restore($wrapped);
    }
});
