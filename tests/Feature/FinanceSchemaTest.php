<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Receipt;
use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('rejects a second payment with the same reference', function () {
    $payment = Payment::factory()->create();

    expect(fn () => Payment::factory()->create([
        'reference' => $payment->reference,
    ]))->toThrow(QueryException::class);
});

it('rejects a second receipt with the same number', function () {
    $receipt = Receipt::factory()->create();

    expect(fn () => Receipt::factory()->create([
        'number' => $receipt->number,
    ]))->toThrow(QueryException::class);
});

it('rejects a second invoice with the same number', function () {
    $invoice = Invoice::factory()->create();

    expect(fn () => Invoice::factory()->create([
        'number' => $invoice->number,
    ]))->toThrow(QueryException::class);
});

it('rejects a duplicate payment event for the same provider', function () {
    $event = PaymentEvent::factory()->create();

    expect(fn () => PaymentEvent::factory()->create([
        'provider' => $event->provider,
        'event_key' => $event->event_key,
    ]))->toThrow(QueryException::class);
});

it('allows the same event key for another provider', function () {
    $event = PaymentEvent::factory()->create([
        'provider' => PaymentGateway::Demo,
    ]);

    PaymentEvent::factory()->create([
        'provider' => PaymentGateway::Remita,
        'event_key' => $event->event_key,
    ]);

    expect(DB::table('payment_events')->count())->toBe(2);
});

it('rejects a second fee structure for the same programme level session and category', function () {
    $structure = FeeStructure::factory()->create();

    expect(fn () => FeeStructure::factory()->create([
        'programme_id' => $structure->programme_id,
        'level' => $structure->level,
        'session_id' => $structure->session_id,
        'fee_category_id' => $structure->fee_category_id,
    ]))->toThrow(QueryException::class);
});

it('allows another category for the same programme level and session', function () {
    $structure = FeeStructure::factory()->create();
    $category = FeeCategory::factory()->create();

    FeeStructure::factory()->create([
        'programme_id' => $structure->programme_id,
        'level' => $structure->level,
        'session_id' => $structure->session_id,
        'fee_category_id' => $category->id,
    ]);

    expect(DB::table('fee_structures')->count())->toBe(2);
});

it('rejects a negative invoice total', function () {
    $invoice = Invoice::factory()->create();

    expect(fn () => DB::table('invoices')->where('id', $invoice->id)->update([
        'total_kobo' => -1,
    ]))->toThrow(QueryException::class);
});

it('rejects a negative invoice adjustment total', function () {
    $invoice = Invoice::factory()->create();

    expect(fn () => DB::table('invoices')->where('id', $invoice->id)->update([
        'adjustments_kobo' => -1,
    ]))->toThrow(QueryException::class);
});

it('rejects a negative invoice paid total', function () {
    $invoice = Invoice::factory()->create();

    expect(fn () => DB::table('invoices')->where('id', $invoice->id)->update([
        'paid_kobo' => -1,
    ]))->toThrow(QueryException::class);
});

it('rejects a zero payment', function () {
    expect(fn () => Payment::factory()->create(['amount_kobo' => 0]))
        ->toThrow(QueryException::class);
});

it('rejects a payment above the invoice balance', function () {
    $invoice = Invoice::factory()->create([
        'total_kobo' => 1000,
        'adjustments_kobo' => 0,
        'paid_kobo' => 0,
        'status' => InvoiceStatus::PartPaid,
    ]);

    expect(fn () => DB::table('invoices')->where('id', $invoice->id)->update([
        'paid_kobo' => 1001,
    ]))->toThrow(QueryException::class);
});

it('rejects an unpaid invoice that already has a payment', function () {
    expect(fn () => Invoice::factory()->create([
        'total_kobo' => 1000,
        'adjustments_kobo' => 0,
        'paid_kobo' => 1,
        'status' => InvoiceStatus::Unpaid,
    ]))->toThrow(QueryException::class);
});

it('rejects a paid invoice whose sums do not match the total', function () {
    expect(fn () => Invoice::factory()->create([
        'total_kobo' => 1000,
        'adjustments_kobo' => 0,
        'paid_kobo' => 500,
        'status' => InvoiceStatus::Paid,
    ]))->toThrow(QueryException::class);
});

it('stores a paid invoice when the sums match the total', function () {
    $invoice = Invoice::factory()->create([
        'total_kobo' => 1000,
        'adjustments_kobo' => 200,
        'paid_kobo' => 800,
        'status' => InvoiceStatus::Paid,
    ]);

    expect($invoice->exists)->toBeTrue();
});

it('rejects a successful payment with no paid_at', function () {
    expect(fn () => Payment::factory()->create([
        'status' => PaymentStatus::Successful,
        'paid_at' => null,
    ]))->toThrow(QueryException::class);
});

it('stores a successful payment when paid_at is set', function () {
    $payment = Payment::factory()->create([
        'status' => PaymentStatus::Successful,
        'paid_at' => now(),
    ]);

    expect($payment->exists)->toBeTrue();
});

it('rejects an adjustment with a blank reason', function () {
    expect(fn () => InvoiceAdjustment::factory()->create(['reason' => '   ']))
        ->toThrow(QueryException::class);
});

it('rejects a payment for a missing invoice', function () {
    expect(fn () => Payment::factory()->create([
        'invoice_id' => 0,
        'student_id' => Student::factory(),
    ]))->toThrow(QueryException::class);
});

it('blocks deleting an invoice that has a payment', function () {
    $payment = Payment::factory()->create();

    expect(fn () => DB::table('invoices')->where('id', $payment->invoice_id)->delete())
        ->toThrow(QueryException::class);
});

it('restricts every finance foreign key', function () {
    $rows = DB::select(
        'select CONSTRAINT_NAME as constraint_name, DELETE_RULE as delete_rule
         from information_schema.referential_constraints
         where CONSTRAINT_SCHEMA = ? and TABLE_NAME in (?, ?, ?, ?, ?, ?, ?)',
        [
            DB::getDatabaseName(),
            'fee_structures',
            'invoices',
            'invoice_items',
            'invoice_adjustments',
            'payments',
            'payment_events',
            'receipts',
        ],
    );

    $delete_rules = collect($rows)->mapWithKeys(
        fn (object $row): array => [$row->constraint_name => $row->delete_rule],
    );

    expect($delete_rules)->toHaveCount(13)
        ->and($delete_rules->every(fn (string $rule): bool => $rule === 'RESTRICT'))->toBeTrue();
});

it('has the finance tables', function () {
    expect(Schema::hasTable('fee_categories'))->toBeTrue()
        ->and(Schema::hasTable('fee_structures'))->toBeTrue()
        ->and(Schema::hasTable('invoices'))->toBeTrue()
        ->and(Schema::hasTable('invoice_items'))->toBeTrue()
        ->and(Schema::hasTable('invoice_adjustments'))->toBeTrue()
        ->and(Schema::hasTable('payments'))->toBeTrue()
        ->and(Schema::hasTable('payment_events'))->toBeTrue()
        ->and(Schema::hasTable('receipts'))->toBeTrue()
        ->and(Schema::hasColumns('invoices', [
            'number',
            'student_id',
            'session_id',
            'total_kobo',
            'adjustments_kobo',
            'paid_kobo',
            'status',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('payments', [
            'reference',
            'gateway',
            'provider_reference',
            'invoice_id',
            'student_id',
            'amount_kobo',
            'status',
            'expires_at',
            'last_checked_at',
            'paid_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('payment_events', [
            'provider',
            'event_key',
            'payload',
            'source',
            'payment_id',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('receipts', [
            'payment_id',
            'number',
        ]))->toBeTrue();
});
