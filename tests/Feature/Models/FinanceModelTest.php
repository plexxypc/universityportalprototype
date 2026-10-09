<?php

declare(strict_types=1);

use App\Enums\InvoiceAdjustmentType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\AcademicSession;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Programme;
use App\Models\Receipt;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a fee category and loads its relationships', function () {
    $active = FeeCategory::factory()->create();
    $inactive = FeeCategory::factory()->inactive()->create();
    $active->load(['feeStructures', 'invoiceItems']);

    expect($active->is_active)->toBeTrue()
        ->and($active->feeStructures)->toBeEmpty()
        ->and($active->invoiceItems)->toBeEmpty()
        ->and($inactive->is_active)->toBeFalse()
        ->and(FeeCategory::query()->active()->pluck('id')->all())->toBe([$active->id]);
});

it('creates a fee structure and loads its relationships', function () {
    $structure = FeeStructure::factory()->create();
    $structure->load(['feeCategory', 'programme', 'academicSession']);

    expect($structure->feeCategory)->toBeInstanceOf(FeeCategory::class)
        ->and($structure->programme)->toBeInstanceOf(Programme::class)
        ->and($structure->academicSession)->toBeInstanceOf(AcademicSession::class)
        ->and($structure->amount_kobo)->toBeGreaterThanOrEqual(1);
});

it('creates an invoice and loads its relationships', function () {
    $invoice = Invoice::factory()->create();
    $invoice->load(['student', 'academicSession', 'items', 'adjustments', 'payments']);

    expect($invoice->student)->toBeInstanceOf(Student::class)
        ->and($invoice->academicSession)->toBeInstanceOf(AcademicSession::class)
        ->and($invoice->items)->toBeEmpty()
        ->and($invoice->adjustments)->toBeEmpty()
        ->and($invoice->payments)->toBeEmpty()
        ->and($invoice->status)->toBe(InvoiceStatus::Unpaid);
});

it('creates each invoice factory state', function () {
    $part_paid = Invoice::factory()->partPaid()->create();
    $paid = Invoice::factory()->paid()->create();
    $cancelled = Invoice::factory()->cancelled()->create();

    expect($part_paid->status)->toBe(InvoiceStatus::PartPaid)
        ->and($part_paid->paid_kobo)->toBeGreaterThan(0)
        ->and($part_paid->paid_kobo + $part_paid->adjustments_kobo)->toBeLessThan($part_paid->total_kobo)
        ->and($paid->status)->toBe(InvoiceStatus::Paid)
        ->and($paid->paid_kobo + $paid->adjustments_kobo)->toBe($paid->total_kobo)
        ->and($cancelled->status)->toBe(InvoiceStatus::Cancelled);
});

it('creates an invoice item and loads its relationships', function () {
    $item = InvoiceItem::factory()->create();
    $item->load(['invoice', 'feeCategory']);

    expect($item->invoice)->toBeInstanceOf(Invoice::class)
        ->and($item->feeCategory)->toBeInstanceOf(FeeCategory::class)
        ->and($item->amount_kobo)->toBeGreaterThanOrEqual(1);
});

it('creates an invoice adjustment and a discount', function () {
    $adjustment = InvoiceAdjustment::factory()->create();
    $adjustment->load(['invoice', 'createdBy']);
    $discount = InvoiceAdjustment::factory()->discount()->create();

    expect($adjustment->invoice)->toBeInstanceOf(Invoice::class)
        ->and($adjustment->createdBy)->toBeInstanceOf(User::class)
        ->and($adjustment->amount_kobo)->toBeGreaterThanOrEqual(1)
        ->and($discount->type)->toBe(InvoiceAdjustmentType::Discount);
});

it('creates a payment for the invoice student', function () {
    $payment = Payment::factory()->create();
    $payment->load(['invoice', 'student', 'events', 'receipt']);

    expect($payment->invoice)->toBeInstanceOf(Invoice::class)
        ->and($payment->student)->toBeInstanceOf(Student::class)
        ->and($payment->student_id)->toBe($payment->invoice->student_id)
        ->and($payment->events)->toBeEmpty()
        ->and($payment->receipt)->toBeNull()
        ->and($payment->amount_kobo)->toBeGreaterThanOrEqual(1)
        ->and($payment->status)->toBe(PaymentStatus::Pending);
});

it('creates each payment factory state', function () {
    $successful = Payment::factory()->successful()->create();
    $failed = Payment::factory()->failed()->create();

    expect($successful->status)->toBe(PaymentStatus::Successful)
        ->and($successful->paid_at)->not->toBeNull()
        ->and($successful->student_id)->toBe($successful->invoice()->value('student_id'))
        ->and($failed->status)->toBe(PaymentStatus::Failed)
        ->and($failed->paid_at)->toBeNull();
});

it('creates a payment event and loads its payment', function () {
    $event = PaymentEvent::factory()->create();
    $event->load('payment');

    expect($event->payment)->toBeInstanceOf(Payment::class);
});

it('creates a receipt for a successful payment', function () {
    $receipt = Receipt::factory()->create();
    $receipt->load('payment');

    expect($receipt->payment)->toBeInstanceOf(Payment::class)
        ->and($receipt->payment->status)->toBe(PaymentStatus::Successful)
        ->and($receipt->payment->paid_at)->not->toBeNull();
});
