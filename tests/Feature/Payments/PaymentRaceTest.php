<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentGateway;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\Payments\DemoGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * True when this process can start and signal another PHP process.
 */
function payment_race_available(): bool
{
    return function_exists('posix_kill')
        && function_exists('proc_open')
        && extension_loaded('pcntl')
        && defined('SIGKILL')
        && defined('SIGTERM');
}

/**
 * @return array<string, string>
 */
function payment_race_env(): array
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
    $env['CACHE_STORE'] = 'database';

    return $env;
}

/**
 * Leave the testing database so child processes can read committed rows.
 */
function payment_race_release(): bool
{
    $connection = DB::connection();
    $wrapped = $connection->transactionLevel() > 0;

    if ($wrapped) {
        $connection->rollBack();
    }

    config(['cache.default' => 'database']);

    return $wrapped;
}

/**
 * Remove rows these races commit outside RefreshDatabase.
 */
function payment_race_cleanup(): void
{
    DB::table('receipts')->delete();
    DB::table('payment_events')->delete();
    DB::table('payments')->delete();
    DB::table('invoice_adjustments')->delete();
    DB::table('invoice_items')->delete();
    DB::table('invoices')->delete();
    DB::table('email_outbox')->delete();
    DB::table('notifications')->delete();
    DB::table('audit_logs')->delete();
    DB::table('sessions')->delete();
    DB::table('jobs')->delete();
    DB::table('failed_jobs')->delete();
    DB::table('cache')->delete();
    DB::table('cache_locks')->delete();
    DB::table('students')->delete();
    DB::table('counters')->delete();
    DB::table('role_assignments')->delete();
    DB::table('programmes')->delete();
    DB::table('departments')->delete();
    DB::table('faculties')->delete();
    DB::table('academic_sessions')->delete();
    DB::table('users')->delete();
}

function payment_race_restore(bool $wrapped): void
{
    payment_race_cleanup();

    if ($wrapped && DB::connection()->transactionLevel() === 0) {
        DB::connection()->beginTransaction();
    }
}

/**
 * @param  list<string>  $modes
 * @param  array<string, mixed>  $payload
 * @return list<string>
 */
function payment_race_run(array $modes, array $payload): array
{
    $go = tempnam(sys_get_temp_dir(), 'pay-go');
    expect($go)->toBeString();

    if (is_file($go)) {
        unlink($go);
    }

    $children = [];
    $files = [(string) $go];
    $results = [];

    foreach ($modes as $mode) {
        $ready = tempnam(sys_get_temp_dir(), 'pay-ready');
        $result = tempnam(sys_get_temp_dir(), 'pay-result');
        $payload_file = tempnam(sys_get_temp_dir(), 'pay-payload');
        expect($ready)->toBeString()->and($result)->toBeString()->and($payload_file)->toBeString();

        if (is_file($ready)) {
            unlink($ready);
        }

        if (is_file($result)) {
            unlink($result);
        }

        file_put_contents((string) $payload_file, json_encode($payload, JSON_THROW_ON_ERROR));
        $files[] = (string) $ready;
        $files[] = (string) $result;
        $files[] = (string) $payload_file;
        $results[] = (string) $result;
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/payment_race_child.php'), (string) $ready, (string) $go, (string) $result, $mode, (string) $payload_file],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            base_path(),
            payment_race_env(),
        );
        expect(is_resource($process))->toBeTrue();

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $status = proc_get_status($process);
        $children[] = ['process' => $process, 'pid' => (int) $status['pid']];
    }

    $ready_deadline = time() + 20;

    while (time() < $ready_deadline) {
        $ready = true;

        foreach ($files as $file) {
            if (str_contains(basename($file), 'pay-ready') && ! is_file($file)) {
                $ready = false;
            }
        }

        if ($ready) {
            break;
        }

        usleep(20000);
    }

    file_put_contents((string) $go, '1');
    $wait_deadline = time() + 25;

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

        if ($running['running']) {
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
 * @return array{invoice: Invoice, payment: Payment, user: User}
 */
function payment_race_invoice(int $total_kobo, int $amount_kobo, string $reference): array
{
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->create([
        'student_id' => $student->id,
        'session_id' => $student->entry_session_id,
        'total_kobo' => $total_kobo,
        'paid_kobo' => 0,
        'adjustments_kobo' => 0,
        'status' => InvoiceStatus::Unpaid,
    ]);
    $payment = Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'student_id' => $student->id,
        'reference' => $reference,
        'amount_kobo' => $amount_kobo,
        'gateway' => PaymentGateway::Demo,
        'expires_at' => now()->addHour(),
    ]);
    Cache::put(DemoGateway::cacheKey($reference), [
        'result' => 'success',
        'amount_kobo' => $amount_kobo,
        'currency' => '566',
        'provider_reference' => 'demo-'.$reference,
    ], now()->addHour());

    return [
        'invoice' => $invoice,
        'payment' => $payment,
        'user' => $user,
    ];
}

it('credits one receipt when the return, notification, and poller settle together', function () {
    if (! payment_race_available()) {
        test()->markTestSkipped('pcntl and posix are required');
    }

    $wrapped = payment_race_release();

    try {
        foreach ([1, 2, 3] as $round) {
            $world = payment_race_invoice(200_000, 100_000, 'P20260000000'.$round);
            $codes = payment_race_run(
                ['callback', 'notification', 'poll'],
                ['reference' => $world['payment']->reference],
            );

            expect($codes)->each(fn ($code) => $code->toBe('ok'))
                ->and(Receipt::query()->count())->toBe(1)
                ->and((int) $world['invoice']->fresh()?->paid_kobo)->toBe(100_000)
                ->and(DB::table('email_outbox')->where('template', 'payment_confirmed')->count())->toBe(1);

            payment_race_cleanup();
        }
    } finally {
        payment_race_restore($wrapped);
    }
});

it('keeps one pending payment when two partial payments start together', function () {
    if (! payment_race_available()) {
        test()->markTestSkipped('pcntl and posix are required');
    }

    $wrapped = payment_race_release();

    try {
        foreach ([1, 2, 3] as $round) {
            $user = User::factory()->create();
            $student = Student::factory()->create(['user_id' => $user->id]);
            $invoice = Invoice::factory()->create([
                'student_id' => $student->id,
                'session_id' => $student->entry_session_id,
                'total_kobo' => 200_000,
                'paid_kobo' => 0,
                'adjustments_kobo' => 0,
                'status' => InvoiceStatus::Unpaid,
            ]);
            $codes = payment_race_run(
                ['initiate', 'initiate'],
                ['user_id' => $user->id, 'invoice_id' => $invoice->id, 'round' => $round],
            );
            sort($codes);

            expect($codes)->toBe(['ok', 'refused'])
                ->and(Payment::query()->where('invoice_id', $invoice->id)->count())->toBe(1)
                ->and((int) $invoice->fresh()?->paid_kobo)->toBe(0);

            payment_race_cleanup();
        }
    } finally {
        payment_race_restore($wrapped);
    }
});

it('keeps the invoice balance when settlement and an adjustment run together', function () {
    if (! payment_race_available()) {
        test()->markTestSkipped('pcntl and posix are required');
    }

    $wrapped = payment_race_release();

    try {
        foreach ([4, 5, 6] as $round) {
            $world = payment_race_invoice(200_000, 150_000, 'P20260000000'.$round);
            $bursar = User::factory()->create();
            RoleAssignment::factory()->create(['user_id' => $bursar->id, 'role' => Role::Bursar]);
            $codes = payment_race_run(
                ['callback', 'adjust'],
                [
                    'reference' => $world['payment']->reference,
                    'invoice_id' => $world['invoice']->id,
                    'actor_id' => $bursar->id,
                ],
            );
            $invoice = $world['invoice']->fresh();
            $paid = (int) $invoice?->paid_kobo;
            $adjustments = (int) $invoice?->adjustments_kobo;

            expect($codes)->each(fn ($code) => $code->toBeIn(['ok', 'refused']))
                ->and($paid + $adjustments)->toBeLessThanOrEqual(200_000)
                ->and(Receipt::query()->count())->toBeLessThanOrEqual(1);

            if (Receipt::query()->count() === 1) {
                expect($paid)->toBe(150_000)->and($adjustments)->toBe(0);
            }

            payment_race_cleanup();
        }
    } finally {
        payment_race_restore($wrapped);
    }
});
