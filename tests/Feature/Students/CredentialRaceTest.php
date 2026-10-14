<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Counter;
use App\Models\User;
use App\Services\CredentialService;
use App\Services\ImportService;
use App\Support\Import\StagedImportStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * True when this process can start and signal another PHP process.
 */
function credential_race_available(): bool
{
    return function_exists('posix_kill')
        && function_exists('proc_open')
        && extension_loaded('pcntl')
        && defined('SIGKILL')
        && defined('SIGTERM');
}

/**
 * Force the testing database for a child process.
 *
 * @return array<string, string>
 */
function credential_race_env(): array
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
    $env['MAIL_MAILER'] = 'array';
    $env['QUEUE_CONNECTION'] = 'sync';

    return $env;
}

/**
 * @return array{process: resource, pid: int}
 */
function credential_race_open(string $mode, string $payload, string $ready, string $go, string $result): array
{
    $sink = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = proc_open(
        [
            PHP_BINARY,
            base_path('tests/Support/credential_race_child.php'),
            $ready,
            $go,
            $result,
            $mode,
            $payload,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $sink, 'w'],
            2 => ['file', $sink, 'w'],
        ],
        $pipes,
        base_path(),
        credential_race_env(),
    );

    expect(is_resource($process))->toBeTrue();

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    $status = proc_get_status($process);

    return [
        'process' => $process,
        'pid' => (int) $status['pid'],
    ];
}

/**
 * @param  array{process: resource, pid: int}  $child
 */
function credential_race_stop(array $child): void
{
    $status = proc_get_status($child['process']);

    if ($status['running'] && function_exists('posix_kill')) {
        posix_kill($child['pid'], SIGKILL);
    }

    proc_close($child['process']);
}

/**
 * @return array{ok: bool, code: string, matric_no: ?string}
 */
function credential_race_read(string $path): array
{
    $contents = is_file($path) ? file_get_contents($path) : false;
    $decoded = is_string($contents) ? json_decode($contents, true) : null;

    if (! is_array($decoded)) {
        return ['ok' => false, 'code' => 'timeout', 'matric_no' => null];
    }

    return [
        'ok' => (bool) ($decoded['ok'] ?? false),
        'code' => is_string($decoded['code'] ?? null) ? $decoded['code'] : 'failed',
        'matric_no' => is_string($decoded['matric_no'] ?? null) ? $decoded['matric_no'] : null,
    ];
}

/**
 * Remove rows these races commit outside RefreshDatabase.
 */
function credential_race_cleanup(): void
{
    DB::table('email_outbox')->delete();
    DB::table('notifications')->delete();
    DB::table('guardians')->delete();
    DB::table('audit_logs')->delete();
    DB::table('sessions')->delete();
    DB::table('jobs')->delete();
    DB::table('failed_jobs')->delete();
    DB::table('cache')->delete();
    DB::table('cache_locks')->delete();
    DB::table('students')->delete();
    DB::table('import_batches')->delete();
    DB::table('counters')->delete();
    DB::table('role_assignments')->delete();
    DB::table('programmes')->delete();
    DB::table('departments')->delete();
    DB::table('faculties')->delete();
    DB::table('academic_sessions')->delete();
    DB::table('users')->delete();
}

/**
 * @param  list<array{process: resource, pid: int}>  $children
 * @param  list<string>  $files
 */
function credential_race_finish(array $children, array $files, bool $wrapped): void
{
    foreach ($children as $child) {
        credential_race_stop($child);
    }

    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    credential_race_cleanup();

    if ($wrapped && DB::connection()->transactionLevel() === 0) {
        DB::connection()->beginTransaction();
    }
}

/**
 * Wait until both children have written a result.
 *
 * @param  list<string>  $results
 */
function credential_race_wait(array $results): void
{
    $deadline = time() + 25;

    while (time() < $deadline) {
        $ready = true;

        foreach ($results as $result) {
            if (! is_file($result)) {
                $ready = false;
            }
        }

        if ($ready) {
            return;
        }

        usleep(50000);
    }
}

it('allocates distinct matric numbers when two processes create the first student', function () {
    if (! credential_race_available()) {
        test()->markTestSkipped('pcntl and posix are required');
    }

    $connection = DB::connection();
    $wrapped = $connection->transactionLevel() > 0;

    if ($wrapped) {
        $connection->rollBack();
    }

    $children = [];
    $files = [];

    try {
        credential_session();
        $actor = credential_actor();
        $programme = credential_programme('CSC');
        expect(Counter::query()->count())->toBe(0);

        $go = tempnam(sys_get_temp_dir(), 'matric-go');
        expect($go)->toBeString();
        $files[] = $go;

        if (is_file($go)) {
            unlink($go);
        }

        $results = [];

        foreach (['race.one@example.com', 'race.two@example.com'] as $email) {
            $ready = tempnam(sys_get_temp_dir(), 'matric-ready');
            $result = tempnam(sys_get_temp_dir(), 'matric-result');
            $payload = tempnam(sys_get_temp_dir(), 'matric-payload');
            expect($ready)->toBeString()->and($result)->toBeString()->and($payload)->toBeString();

            if (is_file($ready)) {
                unlink($ready);
            }

            if (is_file($result)) {
                unlink($result);
            }

            file_put_contents((string) $payload, json_encode([
                'actor_id' => $actor->id,
                'programme_id' => $programme->id,
                'email' => $email,
            ], JSON_THROW_ON_ERROR));
            $files[] = (string) $ready;
            $files[] = (string) $result;
            $files[] = (string) $payload;
            $results[] = (string) $result;
            $children[] = credential_race_open('matric', (string) $payload, (string) $ready, (string) $go, (string) $result);
        }

        $ready_deadline = time() + 20;
        $both_ready = false;

        while (time() < $ready_deadline) {
            $both_ready = true;

            foreach ($files as $file) {
                if (str_contains(basename($file), 'matric-ready') && ! is_file($file)) {
                    $both_ready = false;
                }
            }

            if ($both_ready) {
                break;
            }

            usleep(50000);
        }

        expect($both_ready)->toBeTrue();
        file_put_contents((string) $go, '1');
        credential_race_wait($results);
        $first = credential_race_read($results[0]);
        $second = credential_race_read($results[1]);
        $codes = [$first['code'], $second['code']];
        sort($codes);
        $distinct = $first['matric_no'] !== null
            && $second['matric_no'] !== null
            && $first['matric_no'] !== $second['matric_no'];
        $counter = Counter::query()->where('key', 'matric:CSC:2026')->value('value');

        expect($codes)->toBe(['issued', 'issued'])
            ->and($distinct)->toBeTrue()
            ->and((int) $counter)->toBe(2);
    } finally {
        credential_race_finish($children, $files, $wrapped);
    }
});

it('lets only one of two concurrent resends issue a password', function () {
    if (! credential_race_available()) {
        test()->markTestSkipped('pcntl and posix are required');
    }

    $connection = DB::connection();
    $wrapped = $connection->transactionLevel() > 0;

    if ($wrapped) {
        $connection->rollBack();
    }

    $children = [];
    $files = [];

    try {
        config(['queue.default' => 'sync', 'mail.default' => 'array']);
        credential_session();
        $issued = credential_issue(['email' => 'resend.race@example.com']);
        $user = User::query()->find($issued['student']->user_id);
        expect($user)->not->toBeNull();

        $go = tempnam(sys_get_temp_dir(), 'resend-go');
        expect($go)->toBeString();
        $files[] = $go;

        if (is_file($go)) {
            unlink($go);
        }

        $results = [];

        foreach ([1, 2] as $ignored) {
            $ready = tempnam(sys_get_temp_dir(), 'resend-ready');
            $result = tempnam(sys_get_temp_dir(), 'resend-result');
            $payload = tempnam(sys_get_temp_dir(), 'resend-payload');
            expect($ready)->toBeString()->and($result)->toBeString()->and($payload)->toBeString();

            if (is_file($ready)) {
                unlink($ready);
            }

            if (is_file($result)) {
                unlink($result);
            }

            file_put_contents((string) $payload, json_encode([
                'actor_id' => $issued['actor']->id,
                'user_id' => $user?->id,
                'login_id' => $issued['student']->matric_no,
            ], JSON_THROW_ON_ERROR));
            $files[] = (string) $ready;
            $files[] = (string) $result;
            $files[] = (string) $payload;
            $results[] = (string) $result;
            $children[] = credential_race_open('resend', (string) $payload, (string) $ready, (string) $go, (string) $result);
            unset($ignored);
        }

        $ready_deadline = time() + 20;
        $both_ready = false;

        while (time() < $ready_deadline) {
            $both_ready = true;

            foreach ($files as $file) {
                if (str_contains(basename($file), 'resend-ready') && ! is_file($file)) {
                    $both_ready = false;
                }
            }

            if ($both_ready) {
                break;
            }

            usleep(50000);
        }

        expect($both_ready)->toBeTrue();
        file_put_contents((string) $go, '1');
        credential_race_wait($results);
        $codes = [
            credential_race_read($results[0])['code'],
            credential_race_read($results[1])['code'],
        ];
        sort($codes);
        $resent = AuditLog::query()->where('action', CredentialService::ACTION_RESENT)->count();

        expect($codes)->toBe(['cooldown', 'issued'])
            ->and($resent)->toBe(1);
    } finally {
        credential_race_finish($children, $files, $wrapped);
    }
});

/**
 * Stage one student row with a blank matric number.
 */
function import_race_token(User $actor, string $email): string
{
    $cells = array_fill(0, 17, '');
    $cells[0] = 'Ada';
    $cells[1] = 'Lovelace';
    $cells[3] = $email;
    $cells[4] = '08012345678';
    $cells[5] = 'Female';
    $cells[6] = '03/02/2001';
    $cells[9] = 'CSC';
    $cells[10] = '100';
    $header = 'first_name,last_name,other_names,email,phone,gender,date_of_birth,state_of_origin,address,programme_code,level,entry_session,matric_number,guardian_name,guardian_phone,guardian_email,guardian_relationship';
    $token = app(ImportService::class)->stageUpload($actor, UploadedFile::fake()->createWithContent(
        'students.csv',
        $header."\n".implode(',', $cells)."\n",
    ));
    $map = [];

    foreach (range(0, 16) as $index) {
        $map[[
            'first_name',
            'last_name',
            'other_names',
            'email',
            'phone',
            'gender',
            'date_of_birth',
            'state_of_origin',
            'address',
            'programme_code',
            'level',
            'entry_session',
            'matric_number',
            'guardian_name',
            'guardian_phone',
            'guardian_email',
            'guardian_relationship',
        ][$index]] = $index;
    }

    app(StagedImportStore::class)->saveMap((int) $actor->id, $token, $map);

    return $token;
}

it('allocates distinct matric numbers when two imports run at once', function () {
    if (! credential_race_available()) {
        test()->markTestSkipped('pcntl and posix are required');
    }

    $connection = DB::connection();
    $wrapped = $connection->transactionLevel() > 0;

    if ($wrapped) {
        $connection->rollBack();
    }

    $children = [];
    $files = [];
    $tokens = [];

    try {
        credential_session();
        credential_programme('CSC');
        expect(Counter::query()->count())->toBe(0);

        $go = tempnam(sys_get_temp_dir(), 'import-go');
        expect($go)->toBeString();
        $files[] = $go;

        if (is_file($go)) {
            unlink($go);
        }

        $results = [];

        // Staging replaces that registrar's previous file, so each import has its own actor.
        foreach (['import.race.one@example.com', 'import.race.two@example.com'] as $email) {
            $importer = credential_actor();
            $token = import_race_token($importer, $email);
            $tokens[] = $token;
            $ready = tempnam(sys_get_temp_dir(), 'import-ready');
            $result = tempnam(sys_get_temp_dir(), 'import-result');
            $payload = tempnam(sys_get_temp_dir(), 'import-payload');
            expect($ready)->toBeString()->and($result)->toBeString()->and($payload)->toBeString();

            if (is_file($ready)) {
                unlink($ready);
            }

            if (is_file($result)) {
                unlink($result);
            }

            file_put_contents((string) $payload, json_encode([
                'actor_id' => $importer->id,
                'token' => $token,
                'email' => $email,
            ], JSON_THROW_ON_ERROR));
            $files[] = (string) $ready;
            $files[] = (string) $result;
            $files[] = (string) $payload;
            $results[] = (string) $result;
            $children[] = credential_race_open('import', (string) $payload, (string) $ready, (string) $go, (string) $result);
        }

        $ready_deadline = time() + 20;
        $both_ready = false;

        while (time() < $ready_deadline) {
            $both_ready = true;

            foreach ($files as $file) {
                if (str_contains(basename($file), 'import-ready') && ! is_file($file)) {
                    $both_ready = false;
                }
            }

            if ($both_ready) {
                break;
            }

            usleep(50000);
        }

        expect($both_ready)->toBeTrue();
        file_put_contents((string) $go, '1');
        credential_race_wait($results);
        $first = credential_race_read($results[0]);
        $second = credential_race_read($results[1]);
        $codes = [$first['code'], $second['code']];
        sort($codes);
        $distinct = $first['matric_no'] !== null
            && $second['matric_no'] !== null
            && $first['matric_no'] !== $second['matric_no'];
        $counter = Counter::query()->where('key', 'matric:CSC:2026')->value('value');

        expect($codes)->toBe(['issued', 'issued'])
            ->and($distinct)->toBeTrue()
            ->and((int) $counter)->toBe(2);
    } finally {
        foreach ($tokens as $token) {
            Storage::disk('local')->delete('imports/staged/'.$token.'.json');
            Storage::disk('local')->delete('imports/failed/'.$token.'.csv');
        }

        credential_race_finish($children, $files, $wrapped);
    }
});
