<?php

declare(strict_types=1);

use App\Enums\EmailStatus;
use App\Jobs\SendOutboxEmail;
use App\Models\EmailOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Whether the unique lock for this outbox id is still held.
 */
if (! function_exists('outbox_unique_lock_held')) {
    function outbox_unique_lock_held(int $email_outbox_id): bool
    {
        $suffix = ':'.$email_outbox_id;

        return DB::table('cache_locks')->pluck('key')->contains(
            fn (mixed $key): bool => is_string($key)
                && str_contains($key, 'laravel_unique_job:')
                && str_ends_with($key, $suffix),
        );
    }
}

/**
 * True when this process can signal a worker. Windows PHP has no pcntl.
 */
function outbox_signals_available(): bool
{
    return function_exists('posix_kill')
        && function_exists('proc_open')
        && extension_loaded('pcntl')
        && defined('SIGKILL')
        && defined('SIGTERM');
}

/**
 * Start the paused worker. The caller kills it.
 *
 * @return array{process: resource, pid: int, ready: string, release: string, stdout: string, stderr: string}
 */
function outbox_start_paused_worker(): array
{
    $ready = tempnam(sys_get_temp_dir(), 'outbox-ready');
    $release = tempnam(sys_get_temp_dir(), 'outbox-release');
    $stdout = tempnam(sys_get_temp_dir(), 'outbox-stdout');
    $stderr = tempnam(sys_get_temp_dir(), 'outbox-stderr');

    expect($ready)->toBeString()
        ->and($release)->toBeString()
        ->and($stdout)->toBeString()
        ->and($stderr)->toBeString();

    if (is_file($ready)) {
        unlink($ready);
    }

    if (is_file($release)) {
        unlink($release);
    }

    $process = proc_open(
        [
            PHP_BINARY,
            base_path('tests/Support/pause_outbox_worker.php'),
            (string) $ready,
            (string) $release,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['file', (string) $stdout, 'w'],
            2 => ['file', (string) $stderr, 'w'],
        ],
        $pipes,
        base_path(),
    );

    expect(is_resource($process))->toBeTrue();

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    $status = proc_get_status($process);

    return [
        'process' => $process,
        'pid' => (int) $status['pid'],
        'ready' => (string) $ready,
        'release' => (string) $release,
        'stdout' => (string) $stdout,
        'stderr' => (string) $stderr,
    ];
}

/**
 * Wait until the worker is inside the mailer.
 *
 * @param  array{ready: string}  $worker
 */
function outbox_wait_until_paused(array $worker): void
{
    $deadline = time() + 15;

    while (time() < $deadline && ! is_file($worker['ready'])) {
        usleep(50000);
    }

    expect(is_file($worker['ready']))->toBeTrue();
}

/**
 * Stop the worker and remove the flag files.
 *
 * @param  array{process: resource, pid: int, ready: string, release: string, stdout: string, stderr: string}  $worker
 */
function outbox_stop_worker(array $worker): void
{
    $status = proc_get_status($worker['process']);

    if ($status['running'] && function_exists('posix_kill')) {
        posix_kill($worker['pid'], SIGKILL);
    }

    proc_close($worker['process']);

    foreach (['ready', 'release', 'stdout', 'stderr'] as $name) {
        if (is_file($worker[$name])) {
            unlink($worker[$name]);
        }
    }
}

it('leaves the unique lock held when the worker is killed during a job', function () {
    if (! outbox_signals_available()) {
        test()->markTestSkipped('pcntl and posix are required');
    }

    $connection = DB::connection();
    $wrapped = $connection->transactionLevel() > 0;

    if ($wrapped) {
        $connection->rollBack();
    }

    $worker = null;

    try {
        config(['queue.default' => 'database', 'mail.default' => 'array']);
        $row = EmailOutbox::factory()->create([
            'template' => 'announcement',
            'attempts' => 0,
        ]);
        SendOutboxEmail::dispatch($row->id);

        $worker = outbox_start_paused_worker();
        outbox_wait_until_paused($worker);
        posix_kill($worker['pid'], SIGKILL);

        $deadline = time() + 10;

        while (time() < $deadline && proc_get_status($worker['process'])['running']) {
            usleep(50000);
        }

        $row->refresh();
        $reserved = DB::table('jobs')->whereNotNull('reserved_at')->count();

        expect($reserved)->toBe(1)
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and(outbox_unique_lock_held($row->id))->toBeTrue()
            ->and($row->status)->toBe(EmailStatus::Queued)
            ->and($row->attempts)->toBe(0);
    } finally {
        if (is_array($worker)) {
            outbox_stop_worker($worker);
        }

        DB::table('email_outbox')->delete();
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();
        DB::table('cache')->delete();
        DB::table('cache_locks')->delete();
        DB::table('users')->delete();

        if ($wrapped && $connection->transactionLevel() === 0) {
            $connection->beginTransaction();
        }
    }
});

it('releases the unique lock when the worker is stopped during a job', function () {
    if (! outbox_signals_available()) {
        test()->markTestSkipped('pcntl and posix are required');
    }

    $connection = DB::connection();
    $wrapped = $connection->transactionLevel() > 0;

    if ($wrapped) {
        $connection->rollBack();
    }

    $worker = null;

    try {
        config(['queue.default' => 'database', 'mail.default' => 'array']);
        $row = EmailOutbox::factory()->create([
            'template' => 'announcement',
            'attempts' => 0,
        ]);
        SendOutboxEmail::dispatch($row->id);

        $worker = outbox_start_paused_worker();
        outbox_wait_until_paused($worker);
        posix_kill($worker['pid'], SIGTERM);
        file_put_contents($worker['release'], '1');

        $deadline = time() + 20;

        while (time() < $deadline && proc_get_status($worker['process'])['running']) {
            usleep(50000);
        }

        $row->refresh();

        expect(proc_get_status($worker['process'])['running'])->toBeFalse()
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and(outbox_unique_lock_held($row->id))->toBeFalse()
            ->and($row->status)->toBe(EmailStatus::Sent)
            ->and($row->attempts)->toBe(0);
    } finally {
        if (is_array($worker)) {
            outbox_stop_worker($worker);
        }

        DB::table('email_outbox')->delete();
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();
        DB::table('cache')->delete();
        DB::table('cache_locks')->delete();
        DB::table('users')->delete();

        if ($wrapped && $connection->transactionLevel() === 0) {
            $connection->beginTransaction();
        }
    }
});
