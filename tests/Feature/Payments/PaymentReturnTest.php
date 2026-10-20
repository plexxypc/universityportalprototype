<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentGateway;
use App\Exceptions\PaymentsUnavailableException;
use App\Http\Controllers\Student\PaymentStatusController;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\Payments\DemoGateway;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, invoice: Invoice, payment: Payment}
 */
function return_payment(int $total_kobo = 200_000, int $amount_kobo = 100_000, string $reference = 'P20260000000077'): array
{
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->create([
        'student_id' => $student->id,
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
    ]);

    return [
        'user' => $user,
        'invoice' => $invoice,
        'payment' => $payment,
    ];
}

it('accepts a cross-site return without a session and ignores the body', function () {
    $world = return_payment();
    $response = $this->post('/payments/return', [
        'reference' => $world['payment']->reference,
        'amount' => '999999',
        'status' => 'success',
        'card_number' => '4111111111111111',
    ], ['Origin' => 'https://evil.example']);

    $location = (string) $response->headers->get('Location');

    $response->assertStatus(303);
    expect($location)->toContain('/student/payments/'.$world['payment']->reference)
        ->and($location)->not->toContain('4111111111111111')
        ->and($location)->not->toContain('999999')
        ->and((int) $world['invoice']->fresh()?->paid_kobo)->toBe(0);

    app(DemoGateway::class)->remember($world['payment']->reference, (int) $world['payment']->amount_kobo, 'success');
    Cache::forget(PaymentService::verifyCacheKey($world['payment']->reference));
    $this->post('/payments/return', [
        'reference' => $world['payment']->reference,
        'amount_kobo' => 1,
        'status' => 'failed',
        'currency' => '840',
    ])->assertStatus(303);

    expect($world['payment']->fresh()?->status->value)->toBe('Successful')
        ->and((int) $world['invoice']->fresh()?->paid_kobo)->toBe(100_000);
});

it('shows an overpayment as under review and checks status for the owner', function () {
    $world = return_payment(200_000, 150_000);
    $world['invoice']->paid_kobo = 100_000;
    $world['invoice']->status = InvoiceStatus::PartPaid;
    $world['invoice']->save();
    app(DemoGateway::class)->remember($world['payment']->reference, 150_000, 'success');
    $this->post('/payments/return', ['reference' => $world['payment']->reference])->assertStatus(303);

    $this->actingAs($world['user'])
        ->get('/student/payments/'.$world['payment']->reference)
        ->assertOk()
        ->assertSee(PaymentStatusController::MESSAGE_REVIEW)
        ->assertSee(PaymentStatusController::MESSAGE_REFUNDS);

    $other = User::factory()->create();
    Student::factory()->create(['user_id' => $other->id]);
    $this->actingAs($other)->get('/student/payments/'.$world['payment']->reference)->assertNotFound();
    $this->actingAs($world['user'])->get('/student/payments/P20260000000999')->assertNotFound();
});

it('gives another student the same 404 for a real payment and a missing reference', function () {
    $world = return_payment();
    $other = User::factory()->create();
    Student::factory()->create(['user_id' => $other->id]);
    $real = $this->actingAs($other)->get('/student/payments/'.$world['payment']->reference);
    $missing = $this->actingAs($other)->get('/student/payments/P20260000000999');

    $real->assertNotFound();
    $missing->assertNotFound();

    expect($real->status())->toBe($missing->status());
});

it('limits payment returns per address', function () {
    expect((int) config('payments.return_rate_per_minute'))->toBe(30);

    config(['payments.return_rate_per_minute' => 2]);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77']);
    $body = ['reference' => 'P20260000000999'];

    expect($this->post('/payments/return', $body)->getStatusCode())->toBe(303)
        ->and($this->get('/payments/return?reference=P20260000000999')->getStatusCode())->toBe(303);

    $limited = $this->post('/payments/return', $body);

    $limited->assertStatus(429);
    expect($limited->headers->has('Retry-After'))->toBeTrue()
        ->and($limited->getContent())->toContain('Too Many Requests');
});

it('checks status for the owner and says payments are unavailable when the gateway is closed', function () {
    $world = return_payment();
    app(DemoGateway::class)->remember($world['payment']->reference, (int) $world['payment']->amount_kobo, 'success');

    $this->actingAs($world['user'])
        ->post('/student/payments/'.$world['payment']->reference.'/check')
        ->assertRedirectToRoute('student.payments.show', ['reference' => $world['payment']->reference]);

    expect($world['payment']->fresh()?->status->value)->toBe('Successful');

    config(['payments.provider' => 'remita']);
    $closed = return_payment(200_000, 100_000, 'P20260000000078');

    $this->actingAs($closed['user'])
        ->from('/student/payments/'.$closed['payment']->reference)
        ->post('/student/payments/'.$closed['payment']->reference.'/check')
        ->assertSessionHasErrors(['payment' => PaymentsUnavailableException::MESSAGE]);
});
