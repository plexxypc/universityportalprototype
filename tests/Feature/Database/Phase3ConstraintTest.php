<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Models\AcademicSession;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Result;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Require MySQL to reject the write and name this constraint.
 *
 * @param  callable(): void  $write
 */
function expect_named_constraint(callable $write, string $constraint_name): void
{
    try {
        $write();
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain($constraint_name);

        return;
    }

    throw new RuntimeException('The database accepted a write that '.$constraint_name.' should reject.');
}

/**
 * Require MySQL to reject a negative unsigned amount as out of range.
 *
 * An unsigned column fails before its CHECK runs, so the error names the
 * column and not the constraint.
 *
 * @param  callable(): void  $write
 */
function expect_out_of_range(callable $write): void
{
    try {
        $write();
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('Out of range');

        return;
    }

    throw new RuntimeException('The database accepted a negative amount.');
}

it('rejects a duplicate user email', function () {
    User::factory()->create(['email' => 'ada@example.test']);

    expect(fn () => User::factory()->create(['email' => 'ada@example.test']))
        ->toThrow(QueryException::class);
});

it('switches the current academic session inside a transaction', function () {
    $current = AcademicSession::factory()->current()->create(['name' => '2024/2025']);
    $next = AcademicSession::factory()->create(['name' => '2025/2026']);

    DB::transaction(function () use ($current, $next): void {
        DB::table('academic_sessions')->where('id', $current->id)->update(['is_current' => false]);
        DB::table('academic_sessions')->where('id', $next->id)->update(['is_current' => true]);
    });

    $current_flags = DB::table('academic_sessions')->where('current_flag', 1)->pluck('id');

    expect($current_flags->all())->toBe([$next->id])
        ->and(DB::table('academic_sessions')->where('id', $current->id)->value('current_flag'))->toBeNull();
});

it('rolls back a current session switch that sets the new row first', function () {
    $current = AcademicSession::factory()->current()->create(['name' => '2024/2025']);
    $next = AcademicSession::factory()->create(['name' => '2025/2026']);

    expect(function () use ($current, $next): void {
        DB::transaction(function () use ($current, $next): void {
            DB::table('academic_sessions')->where('id', $next->id)->update(['is_current' => true]);
            DB::table('academic_sessions')->where('id', $current->id)->update(['is_current' => false]);
        });
    })->toThrow(function (QueryException $exception): bool {
        return str_contains($exception->getMessage(), 'academic_sessions_current_flag_unique');
    });

    expect(DB::table('academic_sessions')->where('current_flag', 1)->pluck('id')->all())->toBe([$current->id])
        ->and((int) DB::table('academic_sessions')->where('id', $current->id)->value('is_current'))->toBe(1)
        ->and(DB::table('academic_sessions')->where('id', $next->id)->value('current_flag'))->toBeNull();
});

it('switches the active semester inside a transaction', function () {
    $session = AcademicSession::factory()->current()->create(['name' => '2025/2026']);
    $active = Semester::factory()->active()->create([
        'session_id' => $session->id,
        'name' => 'First',
    ]);
    $next = Semester::factory()->create([
        'session_id' => $session->id,
        'name' => 'Second',
    ]);

    DB::transaction(function () use ($active, $next): void {
        DB::table('semesters')->where('id', $active->id)->update(['is_active' => false]);
        DB::table('semesters')->where('id', $next->id)->update(['is_active' => true]);
    });

    expect(DB::table('semesters')->where('active_flag', 1)->pluck('id')->all())->toBe([$next->id])
        ->and(DB::table('semesters')->where('id', $active->id)->value('active_flag'))->toBeNull();
});

it('rolls back an active semester switch that sets the new row first', function () {
    $session = AcademicSession::factory()->current()->create(['name' => '2025/2026']);
    $active = Semester::factory()->active()->create([
        'session_id' => $session->id,
        'name' => 'First',
    ]);
    $next = Semester::factory()->create([
        'session_id' => $session->id,
        'name' => 'Second',
    ]);

    expect(function () use ($active, $next): void {
        DB::transaction(function () use ($active, $next): void {
            DB::table('semesters')->where('id', $next->id)->update(['is_active' => true]);
            DB::table('semesters')->where('id', $active->id)->update(['is_active' => false]);
        });
    })->toThrow(function (QueryException $exception): bool {
        return str_contains($exception->getMessage(), 'semesters_active_flag_unique');
    });

    expect(DB::table('semesters')->where('active_flag', 1)->pluck('id')->all())->toBe([$active->id])
        ->and((int) DB::table('semesters')->where('id', $active->id)->value('is_active'))->toBe(1)
        ->and(DB::table('semesters')->where('id', $next->id)->value('active_flag'))->toBeNull();
});

it('rejects a zero money amount', function (string $table, string $constraint_name) {
    $id = match ($table) {
        'fee_structures' => FeeStructure::factory()->create()->id,
        'invoice_items' => InvoiceItem::factory()->create()->id,
        'invoice_adjustments' => InvoiceAdjustment::factory()->create()->id,
        default => throw new RuntimeException('Unexpected money table '.$table.'.'),
    };

    expect_named_constraint(
        fn () => DB::table($table)->where('id', $id)->update(['amount_kobo' => 0]),
        $constraint_name,
    );
})->with([
    'fee structure' => ['fee_structures', 'fee_structures_amount_kobo_positive_check'],
    'invoice item' => ['invoice_items', 'invoice_items_amount_kobo_positive_check'],
    'invoice adjustment' => ['invoice_adjustments', 'invoice_adjustments_amount_kobo_positive_check'],
]);

it('rejects a negative money amount as out of range', function (string $table) {
    $id = match ($table) {
        'fee_structures' => FeeStructure::factory()->create()->id,
        'invoice_items' => InvoiceItem::factory()->create()->id,
        'invoice_adjustments' => InvoiceAdjustment::factory()->create()->id,
        'payments' => Payment::factory()->create()->id,
        default => throw new RuntimeException('Unexpected money table '.$table.'.'),
    };

    expect_out_of_range(
        fn () => DB::table($table)->where('id', $id)->update(['amount_kobo' => -1]),
    );
})->with([
    'fee structure' => ['fee_structures'],
    'invoice item' => ['invoice_items'],
    'invoice adjustment' => ['invoice_adjustments'],
    'payment' => ['payments'],
]);

it('rejects paid kobo above total minus adjustments', function () {
    $invoice = Invoice::factory()->create([
        'total_kobo' => 1000,
        'adjustments_kobo' => 200,
        'paid_kobo' => 0,
        'status' => InvoiceStatus::PartPaid,
    ]);

    expect_named_constraint(
        fn () => DB::table('invoices')->where('id', $invoice->id)->update(['paid_kobo' => 801]),
        'invoices_paid_within_balance_check',
    );
});

it('rejects a second receipt for the same payment', function () {
    $receipt = Receipt::factory()->create();

    expect(fn () => Receipt::factory()->create([
        'payment_id' => $receipt->payment_id,
    ]))->toThrow(QueryException::class);
});

it('blocks deleting a course that has a result', function () {
    $result = Result::factory()->create();

    expect(fn () => DB::table('courses')->where('id', $result->course_id)->delete())
        ->toThrow(QueryException::class);
});

it('rolls back every phase 3 migration after migrate fresh and migrates again', function () {
    $database_name = DB::getDatabaseName();

    expect($database_name)->toBe('university_portal_testing');

    if ($database_name !== 'university_portal_testing') {
        throw new RuntimeException('Refusing migrate:fresh on '.$database_name.'.');
    }

    $connection = Schema::getConnection();

    while ($connection->transactionLevel() > 0) {
        $connection->commit();
    }

    try {
        test()->artisan('migrate:fresh')->assertSuccessful();

        expect(Schema::hasTable('audit_logs'))->toBeTrue();

        $steps = (int) DB::table('migrations')
            ->where('migration', '>=', '2026_10_08_160000_add_identity_columns_to_users_table')
            ->count();

        expect($steps)->toBe(43);

        test()->artisan('migrate:rollback', ['--step' => $steps])->assertSuccessful();

        expect(Schema::hasTable('audit_logs'))->toBeFalse()
            ->and(Schema::hasTable('users'))->toBeTrue()
            ->and(Schema::hasTable('cache'))->toBeTrue()
            ->and(Schema::hasTable('jobs'))->toBeTrue()
            ->and(Schema::hasColumn('users', 'status'))->toBeFalse();

        test()->artisan('migrate')->assertSuccessful();

        expect(Schema::hasTable('audit_logs'))->toBeTrue()
            ->and(Schema::hasColumn('users', 'status'))->toBeTrue();
    } finally {
        if (! Schema::hasTable('audit_logs')) {
            test()->artisan('migrate')->assertSuccessful();
        }

        if ($connection->transactionLevel() === 0) {
            $connection->beginTransaction();
        }
    }
});
