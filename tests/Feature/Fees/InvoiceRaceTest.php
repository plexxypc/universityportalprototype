<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Counter;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\Programme;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * True when this process can start and signal another PHP process.
 */
function invoice_race_available(): bool
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
function invoice_race_env(): array
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
    $env['APP_URL'] = $env['APP_URL'] ?? 'http://127.0.0.1:8000';
    $env['MAIL_MAILER'] = 'array';
    $env['QUEUE_CONNECTION'] = 'sync';

    return $env;
}

function invoice_race_release(): bool
{
    $connection = DB::connection();
    $wrapped = $connection->transactionLevel() > 0;

    if ($wrapped) {
        $connection->rollBack();
    }

    return $wrapped;
}

function invoice_race_cleanup(): void
{
    DB::table('invoice_items')->delete();
    DB::table('invoice_adjustments')->delete();
    DB::table('invoices')->delete();
    DB::table('fee_structures')->delete();
    DB::table('fee_categories')->delete();
    DB::table('email_outbox')->delete();
    DB::table('notifications')->delete();
    DB::table('audit_logs')->delete();
    DB::table('sessions')->delete();
    DB::table('jobs')->delete();
    DB::table('failed_jobs')->delete();
    DB::table('students')->delete();
    DB::table('counters')->delete();
    DB::table('role_assignments')->delete();
    DB::table('programmes')->delete();
    DB::table('departments')->delete();
    DB::table('faculties')->delete();
    DB::table('academic_sessions')->delete();
    DB::table('users')->delete();
}

/**
 * @return list<int>
 */
function invoice_race_rounds(): array
{
    $rounds = (int) getenv('INVOICE_RACE_ROUNDS');

    if ($rounds < 1) {
        $rounds = 3;
    }

    return range(1, $rounds);
}

function invoice_race_restore(bool $wrapped): void
{
    invoice_race_cleanup();

    if ($wrapped && DB::connection()->transactionLevel() === 0) {
        DB::connection()->beginTransaction();
    }
}

/**
 * @param  list<string>  $modes
 * @param  array<string, mixed>  $payload
 * @return list<string>
 */
function invoice_race_run(array $modes, array $payload): array
{
    $go = tempnam(sys_get_temp_dir(), 'inv-go');
    expect($go)->toBeString();

    if (is_file($go)) {
        unlink($go);
    }

    $children = [];
    $files = [(string) $go];
    $results = [];

    foreach ($modes as $mode) {
        $ready = tempnam(sys_get_temp_dir(), 'inv-ready');
        $result = tempnam(sys_get_temp_dir(), 'inv-result');
        $payload_file = tempnam(sys_get_temp_dir(), 'inv-payload');
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
            [PHP_BINARY, base_path('tests/Support/invoice_race_child.php'), (string) $ready, (string) $go, (string) $result, $mode, (string) $payload_file],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            base_path(),
            invoice_race_env(),
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
            if (str_contains(basename($file), 'inv-ready') && ! is_file($file)) {
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
 * @return array{bursar: User, registrar: User, programme: Programme, session: AcademicSession}
 */
function invoice_race_world(): array
{
    $bursar = User::factory()->create();
    RoleAssignment::factory()->create(['user_id' => $bursar->id, 'role' => Role::Bursar]);
    $registrar = User::factory()->create();
    RoleAssignment::factory()->create(['user_id' => $registrar->id, 'role' => Role::Registrar]);
    $programme = Programme::factory()->create();
    $session = AcademicSession::factory()->current()->create(['name' => '2026/2027']);
    $category = FeeCategory::factory()->create(['name' => 'Tuition', 'code' => 'tuition-'.fake()->unique()->numerify('###')]);
    FeeStructure::factory()->create([
        'fee_category_id' => $category->id,
        'programme_id' => $programme->id,
        'level' => 100,
        'session_id' => $session->id,
        'amount_kobo' => 1_500_000,
    ]);

    return [
        'bursar' => $bursar,
        'registrar' => $registrar,
        'programme' => $programme,
        'session' => $session,
    ];
}

function invoice_race_numbers_are_contiguous(): bool
{
    $numbers = Invoice::query()->orderBy('number')->pluck('number')->all();
    $year = now()->format('Y');
    $expected = [];

    foreach (array_keys($numbers) as $index) {
        $expected[] = 'INV-'.$year.'-'.str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT);
    }

    return $numbers === $expected
        && (int) Counter::query()->where('key', 'invoice:'.$year)->value('value') === count($numbers);
}

it('issues one invoice when student creation races invoice generation', function () {
    if (! invoice_race_available()) {
        test()->markTestSkipped('pcntl and posix are required');
    }

    $wrapped = invoice_race_release();

    try {
        foreach (invoice_race_rounds() as $round) {
            $world = invoice_race_world();

            foreach (range(1, 4) as $ignored) {
                Student::factory()->create([
                    'programme_id' => $world['programme']->id,
                    'entry_session_id' => $world['session']->id,
                    'level' => 100,
                ]);
            }

            $codes = invoice_race_run(['create', 'confirm'], [
                'registrar_id' => $world['registrar']->id,
                'bursar_id' => $world['bursar']->id,
                'programme_id' => $world['programme']->id,
                'level' => 100,
                'session_id' => $world['session']->id,
                'after_student_id' => 0,
                'email' => 'invoice-race-'.$round.'-'.fake()->unique()->numerify('###').'@example.com',
            ]);
            $confirm = invoice_race_run(['confirm'], [
                'bursar_id' => $world['bursar']->id,
                'programme_id' => $world['programme']->id,
                'level' => 100,
                'session_id' => $world['session']->id,
                'after_student_id' => 0,
            ]);
            $students = Student::query()->where('programme_id', $world['programme']->id)->where('level', 100)->count();
            $open = Invoice::query()
                ->where('session_id', $world['session']->id)
                ->where('status', '!=', 'Cancelled')
                ->count();

            expect($codes)->toBe(['ok', 'ok'])
                ->and($confirm)->toBe(['ok'])
                ->and($open)->toBe($students)
                ->and(invoice_race_numbers_are_contiguous())->toBeTrue();

            invoice_race_cleanup();
        }
    } finally {
        invoice_race_restore($wrapped);
    }
});

it('keeps one invoice per student when two bulk confirms race', function () {
    if (! invoice_race_available()) {
        test()->markTestSkipped('pcntl and posix are required');
    }

    $wrapped = invoice_race_release();

    try {
        foreach (invoice_race_rounds() as $round) {
            $world = invoice_race_world();

            foreach (range(1, 6) as $ignored) {
                Student::factory()->create([
                    'programme_id' => $world['programme']->id,
                    'entry_session_id' => $world['session']->id,
                    'level' => 100,
                ]);
            }

            $codes = invoice_race_run(['confirm', 'confirm'], [
                'bursar_id' => $world['bursar']->id,
                'programme_id' => $world['programme']->id,
                'level' => 100,
                'session_id' => $world['session']->id,
                'after_student_id' => 0,
                'round' => $round,
            ]);
            sort($codes);

            expect($codes)->toBe(['ok', 'ok'])
                ->and(Invoice::query()->where('session_id', $world['session']->id)->where('status', '!=', 'Cancelled')->count())->toBe(6)
                ->and(invoice_race_numbers_are_contiguous())->toBeTrue();

            invoice_race_cleanup();
        }
    } finally {
        invoice_race_restore($wrapped);
    }
});
