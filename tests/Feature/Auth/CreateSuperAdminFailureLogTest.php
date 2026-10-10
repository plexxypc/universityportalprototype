<?php

declare(strict_types=1);

use App\Console\Commands\CreateSuperAdmin;
use App\Enums\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\SuperAdminBootstrapFailure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Tester\ExecutionResult;

uses(RefreshDatabase::class);

/**
 * True when the captured text contains the secret.
 */
function bootstrap_secret_leaked(string $captured, string $secret): bool
{
    return $secret !== '' && str_contains($captured, $secret);
}

/**
 * Run the non-interactive bootstrap command.
 */
function bootstrap_failure_run(): ExecutionResult
{
    $command = app(CreateSuperAdmin::class);
    $command->setLaravel(app());

    return (new CommandTester($command))->run([], [], false);
}

/**
 * Run a command and return its output, exit code, and application log.
 *
 * @param  callable(): ExecutionResult  $run
 * @return array{output: string, log: string, exit_code: int}
 */
function bootstrap_capture(callable $run): array
{
    $path = storage_path('logs/bootstrap-failure-'.bin2hex(random_bytes(8)).'.log');
    $records = [];

    config([
        'logging.default' => 'single',
        'logging.channels.single.path' => $path,
    ]);
    Log::forgetChannel('single');
    Log::forgetChannel('stack');

    Log::listen(function (MessageLogged $event) use (&$records): void {
        $encoded = json_encode($event->context);
        $exception_text = '';
        $exception = $event->context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            $exception_text = $exception->getMessage();
        }

        $records[] = $event->message.' '.(is_string($encoded) ? $encoded : '').' '.$exception_text;
    });

    $output = '';
    $exit_code = 1;

    try {
        $result = $run();
        $output = $result->getDisplay(true);
        $exit_code = $result->statusCode;
    } finally {
        $file = is_file($path) ? (string) file_get_contents($path) : '';

        if (is_file($path)) {
            unlink($path);
        }
    }

    return [
        'output' => $output,
        'log' => implode("\n", $records)."\n".$file,
        'exit_code' => $exit_code,
    ];
}

/**
 * Fail if a secret was written, then require the reason code.
 *
 * @param  array{output: string, log: string, exit_code: int}  $captured
 * @param  list<string>  $secrets
 */
function bootstrap_assert_reported(array $captured, string $code, array $secrets, int $exit_code, bool $one_line): void
{
    $captured_text = $captured['output']."\n".$captured['log'];

    foreach ($secrets as $secret) {
        expect(bootstrap_secret_leaked($captured_text, $secret))->toBeFalse();
    }

    expect($captured['exit_code'])->toBe($exit_code)
        ->and(str_contains($captured['output'], $code))->toBeTrue()
        ->and(str_contains($captured['output'], 'SQLSTATE'))->toBeFalse()
        ->and(str_contains($captured['output'], 'Stack trace'))->toBeFalse();

    if ($one_line) {
        expect(trim($captured['output']))->toBe(SuperAdminBootstrapFailure::from($code)->line($exit_code));
    }
}

/**
 * Put the test connection back after a port change or a committed DDL statement.
 */
function bootstrap_reopen_transaction(): void
{
    DB::disconnect('mysql');
    DB::reconnect('mysql');

    if (DB::transactionLevel() === 0) {
        DB::beginTransaction();
    }
}

afterEach(function (): void {
    bootstrap_set_env(null, null);
});

it('reports missing_variable without the email or the hash', function () {
    $email = 'bootstrap-secret-mailbox@example.test';
    $hash = 'bootstrap-secret-hash-value';
    $password = bootstrap_password();
    bootstrap_set_env($email, null);

    $captured = bootstrap_capture(fn (): ExecutionResult => bootstrap_failure_run());

    bootstrap_assert_reported($captured, 'missing_variable', [$email, $hash, $password], 1, true);
});

it('reports invalid_email without the address or the hash', function () {
    $email = 'bootstrap-secret-mailbox.example.test';
    $hash = 'bootstrap-secret-hash-value';
    $password = bootstrap_password();
    bootstrap_set_env($email, $hash);

    $captured = bootstrap_capture(fn (): ExecutionResult => bootstrap_failure_run());

    bootstrap_assert_reported($captured, 'invalid_email', [$email, $hash, $password], 1, true);
});

it('reports hash_rejected without the email or the hash', function () {
    $email = 'bootstrap-secret-mailbox@example.test';
    $hash = 'bootstrap-secret-hash-value';
    $password = bootstrap_password();
    bootstrap_set_env($email, $hash);

    $captured = bootstrap_capture(fn (): ExecutionResult => bootstrap_failure_run());

    bootstrap_assert_reported($captured, 'hash_rejected', [$email, $hash, $password], 1, true);
});

it('reports duplicate_email without the email, hash, or password', function () {
    $email = 'bootstrap-secret-mailbox@example.test';
    $password = bootstrap_password();
    $hash = bootstrap_hash($password);
    User::factory()->create(['email' => $email]);
    bootstrap_set_env($email, $hash);

    $captured = bootstrap_capture(fn (): ExecutionResult => bootstrap_failure_run());

    bootstrap_assert_reported($captured, 'duplicate_email', [$email, $hash, $password], 1, true);
});

it('reports super_admin_exists without the email, hash, or password', function () {
    $email = 'bootstrap-secret-mailbox@example.test';
    $password = bootstrap_password();
    $hash = bootstrap_hash($password);
    $existing = User::factory()->create([
        'email' => 'already-there@example.test',
        'must_change_password' => false,
    ]);
    RoleAssignment::factory()->create([
        'user_id' => $existing->id,
        'role' => Role::SuperAdmin,
        'faculty_id' => null,
        'department_id' => null,
    ]);
    bootstrap_set_env($email, $hash);

    $captured = bootstrap_capture(fn (): ExecutionResult => bootstrap_failure_run());

    bootstrap_assert_reported($captured, 'super_admin_exists', [$email, $hash, $password], 0, false);
    expect(str_contains($captured['output'], 'exit=0'))->toBeTrue()
        ->and(str_contains($captured['output'], CreateSuperAdmin::REMINDER))->toBeTrue();
});

it('reports database_unreachable without the email, hash, or password', function () {
    $email = 'bootstrap-secret-mailbox@example.test';
    $hash = 'bootstrap-secret-hash-value';
    $password = bootstrap_password();
    bootstrap_set_env($email, $hash);

    $port = config('database.connections.mysql.port');
    $options = config('database.connections.mysql.options');

    try {
        config([
            'database.connections.mysql.port' => 1,
            'database.connections.mysql.options' => array_replace(
                is_array($options) ? $options : [],
                [PDO::ATTR_TIMEOUT => 2],
            ),
        ]);
        DB::purge('mysql');
        DB::disconnect('mysql');

        $captured = bootstrap_capture(fn (): ExecutionResult => bootstrap_failure_run());
    } finally {
        config([
            'database.connections.mysql.port' => $port,
            'database.connections.mysql.options' => $options,
        ]);
        DB::purge('mysql');
        bootstrap_reopen_transaction();
    }

    bootstrap_assert_reported($captured, 'database_unreachable', [$email, $hash, $password], 1, true);
});

it('reports tables_missing when role_assignments is missing', function () {
    $email = 'bootstrap-secret-mailbox@example.test';
    $hash = 'bootstrap-secret-hash-value';
    $password = bootstrap_password();
    bootstrap_set_env($email, $hash);
    Schema::rename('role_assignments', 'role_assignments_held');

    try {
        $captured = bootstrap_capture(fn (): ExecutionResult => bootstrap_failure_run());
    } finally {
        if (Schema::hasTable('role_assignments_held') && ! Schema::hasTable('role_assignments')) {
            Schema::rename('role_assignments_held', 'role_assignments');
        }

        bootstrap_reopen_transaction();
    }

    bootstrap_assert_reported($captured, 'tables_missing', [$email, $hash, $password], 1, true);
    expect(str_contains($captured['output'], 'run migrations first'))->toBeTrue();
});

it('reports unexpected_error without the exception message or the sql bindings', function () {
    $email = 'bootstrap-secret-mailbox@example.test';
    $password = bootstrap_password();
    $hash = bootstrap_hash($password);
    bootstrap_set_env($email, $hash);

    $dispatcher = User::getEventDispatcher();

    try {
        User::creating(function () use ($email, $hash, $password): void {
            $previous = new PDOException('insert failed '.$email.' '.$password);
            $previous->errorInfo = ['HY000', 9999, $email];
            throw new QueryException(
                'mysql',
                'insert into `users` (`email`, `password`) values (?, ?)',
                [$email, $hash, $password],
                $previous,
            );
        });

        $captured = bootstrap_capture(fn (): ExecutionResult => bootstrap_failure_run());
    } finally {
        User::flushEventListeners();

        if ($dispatcher !== null) {
            User::setEventDispatcher($dispatcher);
        }
    }

    bootstrap_assert_reported($captured, 'unexpected_error', [$email, $hash, $password], 1, true);
});
