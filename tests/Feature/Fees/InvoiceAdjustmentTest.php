<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\Payment;
use App\Models\Programme;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Issue one invoice and return the bursar who may adjust it.
 *
 * @return array{actor: User, invoice: Invoice}
 */
function adjustment_invoice(): array
{
    $actor = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $actor->id,
        'role' => Role::Bursar,
    ]);
    $programme = Programme::factory()->create();
    $session = AcademicSession::factory()->current()->create(['name' => '2026/2027']);
    $tuition = FeeCategory::factory()->create(['name' => 'Tuition', 'code' => 'tuition', 'sort' => 1]);
    FeeStructure::factory()->create([
        'fee_category_id' => $tuition->id,
        'programme_id' => $programme->id,
        'level' => 100,
        'session_id' => $session->id,
        'amount_kobo' => 1_750_000,
    ]);
    $student = Student::factory()->create([
        'programme_id' => $programme->id,
        'entry_session_id' => $session->id,
        'level' => 100,
        'first_name' => 'Zainab',
    ]);
    app(InvoiceService::class)->confirmChunk(
        $actor,
        (int) $programme->id,
        100,
        (int) $session->id,
        0,
        adjustment_request(),
    );

    return [
        'actor' => $actor,
        'invoice' => Invoice::query()->where('student_id', $student->id)->firstOrFail(),
    ];
}

function adjustment_request(): Request
{
    return Request::create('/staff/invoices', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.50',
    ]);
}

function adjustment_role(Role $role): User
{
    $user = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

it('adds an adjustment within the unpaid balance and audits it', function () {
    $world = adjustment_invoice();
    $service = app(InvoiceService::class);
    $service->addAdjustment(
        $world['actor'],
        (int) $world['invoice']->id,
        'Scholarship',
        '10000.00',
        'Merit award',
        adjustment_request(),
    );
    $service->addAdjustment(
        $world['actor'],
        (int) $world['invoice']->id,
        'Waiver',
        '250.00',
        'ICT waiver',
        adjustment_request(),
    );
    $invoice = $world['invoice']->fresh();
    $rows = InvoiceAdjustment::query()->where('invoice_id', $invoice?->id)->get();
    $audit = AuditLog::query()->where('action', InvoiceService::ACTION_ADJUSTMENT_ADDED)->first();

    expect($rows)->toHaveCount(2)
        ->and($rows->sum('amount_kobo'))->toBe(1_025_000)
        ->and((int) $invoice?->adjustments_kobo)->toBe(1_025_000)
        ->and((int) $invoice?->paid_kobo)->toBe(0)
        ->and($invoice?->status->value)->toBe('Unpaid')
        ->and($audit?->after['amount_kobo'] ?? null)->toBe(1_000_000)
        ->and($audit?->after['type'] ?? null)->toBe('Scholarship')
        ->and($audit?->after['reason'] ?? null)->toBe('Merit award')
        ->and(json_encode($audit?->after))->not->toContain('Zainab');

    expect(fn () => $service->addAdjustment(
        $world['actor'],
        (int) $world['invoice']->id,
        'Discount',
        '0',
        'Nothing',
        adjustment_request(),
    ))->toThrow(ValidationException::class, InvoiceService::MESSAGE_AMOUNT);
});

it('rejects an adjustment above the balance reserved by pending payments', function () {
    $world = adjustment_invoice();
    Payment::factory()->create([
        'invoice_id' => $world['invoice']->id,
        'amount_kobo' => 500_000,
        'status' => PaymentStatus::Pending,
        'expires_at' => now()->addMinutes(30),
    ]);

    expect(fn () => app(InvoiceService::class)->addAdjustment(
        $world['actor'],
        (int) $world['invoice']->id,
        'Discount',
        '17500.00',
        'Too much',
        adjustment_request(),
    ))->toThrow(ValidationException::class, InvoiceService::MESSAGE_BALANCE);

    $invoice = $world['invoice']->fresh();

    expect(InvoiceAdjustment::query()->count())->toBe(0)
        ->and((int) $invoice?->adjustments_kobo)->toBe(0)
        ->and((int) $invoice?->paid_kobo)->toBe(0);
});

it('removes an adjustment for the super admin and refuses the bursar and the registrar', function () {
    $world = adjustment_invoice();
    $service = app(InvoiceService::class);
    $adjustment = $service->addAdjustment(
        $world['actor'],
        (int) $world['invoice']->id,
        'Discount',
        '100.00',
        'Early payment',
        adjustment_request(),
    );
    $registrar = adjustment_role(Role::Registrar);
    $super_admin = adjustment_role(Role::SuperAdmin);

    expect(fn () => $service->removeAdjustment(
        $world['actor'],
        (int) $world['invoice']->id,
        (int) $adjustment->id,
        'Wrong amount',
        adjustment_request(),
    ))->toThrow(AuthorizationException::class, InvoiceService::MESSAGE_REMOVE);

    expect(fn () => $service->addAdjustment(
        $registrar,
        (int) $world['invoice']->id,
        'Waiver',
        '50.00',
        'Not allowed',
        adjustment_request(),
    ))->toThrow(AuthorizationException::class, InvoiceService::MESSAGE_MANAGE);

    expect(fn () => $service->removeAdjustment(
        $registrar,
        (int) $world['invoice']->id,
        (int) $adjustment->id,
        'Not allowed',
        adjustment_request(),
    ))->toThrow(AuthorizationException::class, InvoiceService::MESSAGE_REMOVE);

    $invoice = $service->removeAdjustment(
        $super_admin,
        (int) $world['invoice']->id,
        (int) $adjustment->id,
        'Entered twice',
        adjustment_request(),
    );
    $audit = AuditLog::query()->where('action', InvoiceService::ACTION_ADJUSTMENT_REMOVED)->first();

    expect(InvoiceAdjustment::query()->whereKey($adjustment->id)->exists())->toBeFalse()
        ->and((int) $invoice->adjustments_kobo)->toBe(0)
        ->and($invoice->status->value)->toBe('Unpaid')
        ->and($audit?->before['amount_kobo'] ?? null)->toBe(10_000)
        ->and($audit?->before['adjustment_id'] ?? null)->toBe((int) $adjustment->id)
        ->and($audit?->after['reason'] ?? null)->toBe('Entered twice')
        ->and(json_encode($audit?->before))->not->toContain('Zainab')
        ->and(json_encode($audit?->after))->not->toContain('Zainab');
});

it('releases the reservation when a pending payment expires', function () {
    $world = adjustment_invoice();
    Payment::factory()->create([
        'invoice_id' => $world['invoice']->id,
        'amount_kobo' => 1_750_000,
        'status' => PaymentStatus::Pending,
        'expires_at' => now()->subMinute(),
    ]);

    app(InvoiceService::class)->addAdjustment(
        $world['actor'],
        (int) $world['invoice']->id,
        'Scholarship',
        '17500.00',
        'Full scholarship',
        adjustment_request(),
    );
    $invoice = $world['invoice']->fresh();
    $payment = Payment::query()->where('invoice_id', $world['invoice']->id)->first();

    expect((int) $invoice?->adjustments_kobo)->toBe(1_750_000)
        ->and((int) $invoice?->paid_kobo)->toBe(0)
        ->and($invoice?->status->value)->toBe('Paid')
        ->and($payment?->status->value)->toBe('Pending');
});
