<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\EmailOutbox;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Receipt;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\Payments\DemoGateway;
use App\Services\PaymentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, invoice: Invoice, payment: Payment}
 */
function settlement_payment(int $total_kobo = 200_000, int $amount_kobo = 100_000): array
{
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->create([
        'student_id' => $student->id,
        'total_kobo' => $total_kobo,
        'adjustments_kobo' => 0,
        'paid_kobo' => 0,
        'status' => InvoiceStatus::Unpaid,
    ]);
    $started = app(PaymentService::class)->initiate(
        $user,
        (int) $invoice->id,
        number_format($amount_kobo / 100, 2, '.', ''),
        Request::create('/student/fees/1/pay', 'POST'),
    );

    return [
        'user' => $user,
        'invoice' => $invoice->fresh() ?? $invoice,
        'payment' => $started->payment,
    ];
}

function settlement_outcome(Payment $payment, string $result, ?string $currency = '566', ?int $amount_kobo = null): void
{
    Cache::put(DemoGateway::cacheKey($payment->reference), [
        'result' => $result,
        'amount_kobo' => $amount_kobo ?? (int) $payment->amount_kobo,
        'currency' => $currency,
        'card_number' => '4111111111111111',
    ], now()->addHour());
    Cache::forget(PaymentService::verifyCacheKey($payment->reference));
}

it('credits a matching success once and creates one receipt and one email', function () {
    $world = settlement_payment();
    settlement_outcome($world['payment'], 'success', null);
    $service = app(PaymentService::class);
    $service->verifyAndSettle($world['payment']->reference, 'callback');
    Cache::forget(PaymentService::verifyCacheKey($world['payment']->reference));
    $service->verifyAndSettle($world['payment']->reference, 'callback');
    $payment = $world['payment']->fresh();
    $invoice = $world['invoice']->fresh();
    $blob = (string) json_encode([
        PaymentEvent::query()->get(['payload']),
        DB::table('audit_logs')->get(['before', 'after']),
        EmailOutbox::query()->get(),
        DB::table('jobs')->get(),
        DB::table('failed_jobs')->get(),
    ]);

    expect($payment?->status->value)->toBe('Successful')
        ->and((int) $invoice?->paid_kobo)->toBe(100_000)
        ->and($invoice?->status->value)->toBe('Part-paid')
        ->and(Receipt::query()->count())->toBe(1)
        ->and(EmailOutbox::query()->where('template', 'payment_confirmed')->count())->toBe(1)
        ->and(Notification::query()->count())->toBe(1)
        ->and(str_contains($blob, '4111111111111111'))->toBeFalse();
});

it('does not call the gateway for an unknown reference and throttles a known one', function () {
    $world = settlement_payment();
    $reads = 0;
    $count = function (object $event) use (&$reads): void {
        if (str_contains((string) $event->key, 'demo-payment:')) {
            $reads++;
        }
    };
    Event::listen(CacheHit::class, $count);
    Event::listen(CacheMissed::class, $count);
    app(PaymentService::class)->verifyAndSettle('P20260000000999', 'manual');

    expect($reads)->toBe(0);

    settlement_outcome($world['payment'], 'success');
    app(PaymentService::class)->verifyAndSettle($world['payment']->reference, 'callback');
    app(PaymentService::class)->verifyAndSettle($world['payment']->reference, 'poll');

    expect($reads)->toBe(1)
        ->and(Receipt::query()->count())->toBe(1);
});

it('credits nothing when the amount, currency, or reference does not match', function (string $field) {
    $world = settlement_payment();
    settlement_outcome($world['payment'], 'success');
    $key = DemoGateway::cacheKey($world['payment']->reference);
    $stored = Cache::get($key);
    $stored = is_array($stored) ? $stored : [];

    if ($field === 'amount') {
        $stored['amount_kobo'] = 1;
    } elseif ($field === 'currency') {
        $stored['currency'] = '840';
    } else {
        $stored['reference'] = 'P20260000000998';
    }

    Cache::put($key, $stored, now()->addHour());
    Cache::forget(PaymentService::verifyCacheKey($world['payment']->reference));
    app(PaymentService::class)->verifyAndSettle($world['payment']->reference, 'callback');

    expect((int) $world['invoice']->fresh()?->paid_kobo)->toBe(0)
        ->and(PaymentEvent::query()->where('event_key', 'mismatch:'.$world['payment']->reference)->count())->toBe(1)
        ->and(Receipt::query()->count())->toBe(0);
})->with(['amount', 'currency', 'reference']);

it('credits a late success on an expired payment when the balance allows it', function () {
    $world = settlement_payment();
    $world['payment']->status = PaymentStatus::Expired;
    $world['payment']->expires_at = now()->subMinute();
    $world['payment']->save();
    settlement_outcome($world['payment'], 'success');
    app(PaymentService::class)->verifyAndSettle($world['payment']->reference, 'poll');

    expect($world['payment']->fresh()?->status->value)->toBe('Successful')
        ->and((int) $world['invoice']->fresh()?->paid_kobo)->toBe(100_000);
});

it('leaves an overpayment pending for review and lets finance resolve it', function () {
    $world = settlement_payment(200_000, 150_000);
    $world['invoice']->paid_kobo = 100_000;
    $world['invoice']->status = InvoiceStatus::PartPaid;
    $world['invoice']->save();
    settlement_outcome($world['payment'], 'success');
    $service = app(PaymentService::class);
    $service->verifyAndSettle($world['payment']->reference, 'callback');
    $bursar = User::factory()->create();
    RoleAssignment::factory()->create(['user_id' => $bursar->id, 'role' => Role::Bursar]);

    expect($world['payment']->fresh()?->status->value)->toBe('Pending')
        ->and((int) $world['invoice']->fresh()?->paid_kobo)->toBe(100_000)
        ->and($service->openReviewCount())->toBe(1)
        ->and($service->underReview($world['payment']->fresh() ?? $world['payment']))->toBeTrue();

    $service->resolveReview($bursar, $world['payment']->reference, 'Recorded outside', Request::create('/staff/invoices', 'POST'));

    expect($service->openReviewCount())->toBe(0)
        ->and(PaymentEvent::query()->where('event_key', 'review_resolved:'.$world['payment']->reference)->count())->toBe(1);
});

it('reverses a successful payment once and keeps the row', function () {
    $world = settlement_payment(100_000, 100_000);
    settlement_outcome($world['payment'], 'success');
    $service = app(PaymentService::class);
    $service->verifyAndSettle($world['payment']->reference, 'callback');
    $bursar = User::factory()->create();
    RoleAssignment::factory()->create(['user_id' => $bursar->id, 'role' => Role::Bursar]);
    $registrar = User::factory()->create();
    RoleAssignment::factory()->create(['user_id' => $registrar->id, 'role' => Role::Registrar]);

    expect(fn () => $service->reverse($registrar, (int) $world['payment']->id, 'No', Request::create('/', 'POST')))
        ->toThrow(AuthorizationException::class);

    $reversed = $service->reverse($bursar, (int) $world['payment']->id, 'Paid twice', Request::create('/', 'POST'));

    expect($reversed->status->value)->toBe('Reversed')
        ->and(Payment::query()->whereKey($world['payment']->id)->exists())->toBeTrue()
        ->and((int) $world['invoice']->fresh()?->paid_kobo)->toBe(0)
        ->and($world['invoice']->fresh()?->status->value)->toBe('Unpaid')
        ->and(Receipt::query()->count())->toBe(1);
});

it('releases a pending payment when the provider reports failure or cancellation', function (string $result, string $status) {
    $world = settlement_payment();
    settlement_outcome($world['payment'], $result);
    app(PaymentService::class)->verifyAndSettle($world['payment']->reference, 'notification');

    expect($world['payment']->fresh()?->status->value)->toBe($status)
        ->and((int) $world['invoice']->fresh()?->paid_kobo)->toBe(0);
})->with([
    'failed' => ['failed', 'Failed'],
    'cancelled' => ['cancelled', 'Cancelled'],
]);

it('expires a pending payment and does not change paid_kobo', function () {
    $world = settlement_payment();
    $world['payment']->expires_at = now()->subMinute();
    $world['payment']->save();
    settlement_outcome($world['payment'], 'pending');
    app(PaymentService::class)->verifyAndSettle($world['payment']->reference, 'poll');

    expect($world['payment']->fresh()?->status->value)->toBe('Expired')
        ->and((int) $world['invoice']->fresh()?->paid_kobo)->toBe(0);
});

it('refuses an update to a payment event', function () {
    $world = settlement_payment();
    settlement_outcome($world['payment'], 'success');
    app(PaymentService::class)->verifyAndSettle($world['payment']->reference, 'callback');
    $event = PaymentEvent::query()->first();

    expect(fn () => $event?->update(['event_key' => 'changed']))->toThrow(RuntimeException::class);
});

it('locks the payment row before the invoice row', function () {
    $world = settlement_payment();
    settlement_outcome($world['payment'], 'success');
    $order = [];
    DB::listen(function (object $query) use (&$order): void {
        $sql = strtolower($query->sql);

        if (! str_contains($sql, 'for update')) {
            return;
        }

        if (str_contains($sql, 'payments')) {
            $order[] = 'payment';
        } elseif (str_contains($sql, 'invoices')) {
            $order[] = 'invoice';
        }
    });
    app(PaymentService::class)->verifyAndSettle($world['payment']->reference, 'callback');

    expect($order[0] ?? null)->toBe('payment')
        ->and($order[1] ?? null)->toBe('invoice');
});
