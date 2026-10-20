<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\Payments\DemoGateway;
use App\Services\PaymentService;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, invoice: Invoice, payment: Payment}
 */
function notify_payment(): array
{
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->create([
        'student_id' => $student->id,
        'total_kobo' => 200_000,
        'paid_kobo' => 0,
        'adjustments_kobo' => 0,
        'status' => InvoiceStatus::Unpaid,
    ]);
    $payment = Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'student_id' => $student->id,
        'reference' => 'P20260000000088',
        'amount_kobo' => 100_000,
        'gateway' => PaymentGateway::Demo,
    ]);

    return [
        'user' => $user,
        'invoice' => $invoice,
        'payment' => $payment,
    ];
}

function notify_send(string $provider, string $body): TestResponse
{
    return test()->call('POST', '/payments/notify/'.$provider, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $body);
}

it('gives the same empty response for a known and an unknown reference', function () {
    $world = notify_payment();
    $known = notify_send('demo', json_encode([
        'reference' => $world['payment']->reference,
        'status' => 'success',
        'amount' => 1,
        'card_number' => '4111111111111111',
    ], JSON_THROW_ON_ERROR));
    $unknown = notify_send('demo', json_encode([
        'reference' => 'P20260000000999',
        'status' => 'success',
        'amount' => 999_999,
    ], JSON_THROW_ON_ERROR));

    expect($known->getStatusCode())->toBe(200)
        ->and($unknown->getStatusCode())->toBe(200)
        ->and($known->getContent())->toBe($unknown->getContent())
        ->and($known->getContent())->toBe('')
        ->and((int) $world['invoice']->fresh()?->paid_kobo)->toBe(0);

    app(DemoGateway::class)->remember($world['payment']->reference, (int) $world['payment']->amount_kobo, 'success');
    Cache::forget(PaymentService::verifyCacheKey($world['payment']->reference));
    notify_send('demo', json_encode([
        'reference' => $world['payment']->reference,
        'amount_kobo' => 1,
        'status' => 'failed',
    ], JSON_THROW_ON_ERROR))->assertOk();

    expect($world['payment']->fresh()?->status->value)->toBe('Successful')
        ->and((int) $world['invoice']->fresh()?->paid_kobo)->toBe(100_000);
});

it('does not process an oversized notification or another provider', function () {
    $world = notify_payment();
    app(DemoGateway::class)->remember($world['payment']->reference, (int) $world['payment']->amount_kobo, 'success');
    $padded = str_repeat('a', (int) config('payments.notification_max_bytes') + 1);
    $oversized = notify_send('demo', $padded);
    $other = notify_send('remita', json_encode([
        'reference' => $world['payment']->reference,
        'status' => 'success',
    ], JSON_THROW_ON_ERROR));

    expect($oversized->getStatusCode())->toBe(200)
        ->and($oversized->getContent())->toBe('')
        ->and($other->getStatusCode())->toBe(200)
        ->and($other->getContent())->toBe('')
        ->and($world['payment']->fresh()?->status->value)->toBe('Pending');
});

it('rate-limits notification posts', function () {
    config(['payments.notification_rate_per_minute' => 2]);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50']);
    $body = json_encode(['reference' => 'P20260000000999'], JSON_THROW_ON_ERROR);

    expect(notify_send('demo', $body)->getStatusCode())->toBe(200)
        ->and(notify_send('demo', $body)->getStatusCode())->toBe(200)
        ->and(notify_send('demo', $body)->getStatusCode())->toBe(429);
});

it('does not ask the gateway again when a return already verified the reference', function () {
    $world = notify_payment();
    $reads = 0;
    $count = function (object $event) use (&$reads): void {
        if (str_contains((string) $event->key, 'demo-payment:')) {
            $reads++;
        }
    };
    Event::listen(CacheHit::class, $count);
    Event::listen(CacheMissed::class, $count);
    $this->post('/payments/return', ['reference' => $world['payment']->reference])->assertStatus(303);
    notify_send('demo', json_encode(['reference' => $world['payment']->reference], JSON_THROW_ON_ERROR));

    expect($reads)->toBe(1);
});

it('stops the poller when the budget is spent and skips a recent check', function () {
    $world = notify_payment();
    app(DemoGateway::class)->remember($world['payment']->reference, (int) $world['payment']->amount_kobo, 'success');
    config(['payments.poll_budget_seconds' => 0]);
    $this->artisan('payments:poll-pending')->assertSuccessful();

    expect($world['payment']->fresh()?->status->value)->toBe('Pending');

    config(['payments.poll_budget_seconds' => 20]);
    $world['payment']->last_checked_at = now();
    $world['payment']->save();
    $this->artisan('payments:poll-pending')->assertSuccessful();

    expect($world['payment']->fresh()?->status->value)->toBe('Pending');

    $world['payment']->last_checked_at = now()->subMinutes(5);
    $world['payment']->save();
    Cache::forget(PaymentService::verifyCacheKey($world['payment']->reference));
    $this->artisan('payments:poll-pending')->assertSuccessful();

    expect($world['payment']->fresh()?->status->value)->toBe('Successful');
});

it('schedules the payment poller every minute without overlapping', function () {
    $events = collect(Schedule::events())->filter(
        fn (object $event): bool => str_contains((string) ($event->command ?? ''), 'payments:poll-pending'),
    );
    $event = $events->first();

    expect($events)->toHaveCount(1)
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
