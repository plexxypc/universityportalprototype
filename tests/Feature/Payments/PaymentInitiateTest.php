<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Exceptions\PaymentsUnavailableException;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * An unpaid invoice owned by a student.
 *
 * @return array{user: User, invoice: Invoice}
 */
function payment_start_invoice(int $total_kobo = 200_000): array
{
    $user = User::factory()->create();
    $student = Student::factory()->create([
        'user_id' => $user->id,
        'first_name' => 'Zainab',
    ]);
    $invoice = Invoice::factory()->create([
        'student_id' => $student->id,
        'total_kobo' => $total_kobo,
        'adjustments_kobo' => 0,
        'paid_kobo' => 0,
        'status' => InvoiceStatus::Unpaid,
    ]);

    return [
        'user' => $user,
        'invoice' => $invoice,
    ];
}

function payment_start_request(): Request
{
    return Request::create('/student/fees/1/pay', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.50',
    ]);
}

it('starts a pending payment for the invoice student and leaves paid_kobo unchanged', function () {
    $world = payment_start_invoice();
    $response = $this->actingAs($world['user'])->post('/student/fees/'.$world['invoice']->id.'/pay', [
        'amount' => '1000.00',
    ]);
    $payment = Payment::query()->where('invoice_id', $world['invoice']->id)->first();
    $invoice = $world['invoice']->fresh();
    $audit = AuditLog::query()->where('action', PaymentService::ACTION_INITIATED)->first();
    $year = now()->format('Y');

    $response->assertRedirect();

    expect($payment)->not->toBeNull()
        ->and($payment?->reference)->toBe('P'.$year.'0000000001')
        ->and($payment?->provider_reference)->toBe('demo-P'.$year.'0000000001')
        ->and((int) $payment?->student_id)->toBe((int) $invoice?->student_id)
        ->and((int) $payment?->amount_kobo)->toBe(100_000)
        ->and($payment?->status->value)->toBe('Pending')
        ->and((int) $invoice?->paid_kobo)->toBe(0)
        ->and($response->headers->get('Location'))->toContain('/student/payments/demo/P'.$year)
        ->and($audit?->after['amount_kobo'] ?? null)->toBe(100_000)
        ->and(json_encode($audit?->after))->not->toContain('Zainab');
});

it('rejects an amount above the balance reserved by pending payments', function () {
    $world = payment_start_invoice();
    Payment::factory()->create([
        'invoice_id' => $world['invoice']->id,
        'student_id' => $world['invoice']->student_id,
        'amount_kobo' => 150_000,
        'status' => PaymentStatus::Pending,
        'expires_at' => now()->addMinutes(30),
    ]);

    expect(fn () => app(PaymentService::class)->initiate(
        $world['user'],
        (int) $world['invoice']->id,
        '1000.00',
        payment_start_request(),
    ))->toThrow(ValidationException::class, PaymentService::MESSAGE_BALANCE);

    expect(Payment::query()->count())->toBe(1)
        ->and((int) $world['invoice']->fresh()?->paid_kobo)->toBe(0);
});

it('accepts the whole remaining balance and rejects a smaller instalment', function () {
    $small = payment_start_invoice(5_000);
    $started = app(PaymentService::class)->initiate(
        $small['user'],
        (int) $small['invoice']->id,
        '50.00',
        payment_start_request(),
    );
    $large = payment_start_invoice();

    expect((int) $started->payment->amount_kobo)->toBe(5_000);

    expect(fn () => app(PaymentService::class)->initiate(
        $large['user'],
        (int) $large['invoice']->id,
        '50.00',
        payment_start_request(),
    ))->toThrow(ValidationException::class, PaymentService::MESSAGE_INSTALMENT);
});

it('locks only the invoice row when a payment starts', function () {
    $world = payment_start_invoice();
    $locked = [];
    DB::listen(function (object $query) use (&$locked): void {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $locked[] = strtolower($query->sql);
        }
    });

    app(PaymentService::class)->initiate(
        $world['user'],
        (int) $world['invoice']->id,
        '1000.00',
        payment_start_request(),
    );

    $sql = implode("\n", $locked);

    expect($sql)->toContain('invoices')
        ->and($sql)->not->toContain('payments');
});

it('refuses another student and a super admin, and shows payments are unavailable', function () {
    $world = payment_start_invoice();
    $other = User::factory()->create();
    Student::factory()->create(['user_id' => $other->id]);
    $super_admin = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $super_admin->id,
        'role' => Role::SuperAdmin,
    ]);
    Student::factory()->create(['user_id' => $super_admin->id]);

    expect(fn () => app(PaymentService::class)->initiate(
        $other,
        (int) $world['invoice']->id,
        '1000.00',
        payment_start_request(),
    ))->toThrow(ValidationException::class, PaymentService::MESSAGE_MISSING);

    expect(fn () => app(PaymentService::class)->initiate(
        $super_admin,
        (int) $world['invoice']->id,
        '1000.00',
        payment_start_request(),
    ))->toThrow(AuthorizationException::class, PaymentService::MESSAGE_MAKE);

    config(['payments.provider' => 'remita']);

    $this->actingAs($world['user'])
        ->from('/student/fees/'.$world['invoice']->id)
        ->post('/student/fees/'.$world['invoice']->id.'/pay', ['amount' => '1000.00'])
        ->assertRedirect()
        ->assertSessionHasErrors('payment');

    expect(session('errors')->first('payment'))->toBe(PaymentsUnavailableException::MESSAGE)
        ->and(Payment::query()->where('invoice_id', $world['invoice']->id)->count())->toBe(0);
});

it('rejects a reversed payment that has no reversal fields', function () {
    expect(fn () => Payment::factory()->create([
        'status' => PaymentStatus::Reversed,
    ]))->toThrow(QueryException::class);
});
