<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentEventSource;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Student;
use App\Models\User;
use App\Services\Payments\InterswitchGateway;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/**
 * Requery body copied from the Web Checkout page on docs.interswitchgroup.com.
 */
const INTERSWITCH_REQUERY_SAMPLE = <<<'JSON'
{
    "Amount": 66666600,
    "CardNumber": "",
    "MerchantReference": "933437251484",
    "PaymentReference": "UBA|API|MX187|25-07-2018|249510|719456",
    "RetrievalReferenceNumber": "000647661227",
    "SplitAccounts": [],
    "TransactionDate": "2018-07-25T06:57:24",
    "ResponseCode": "00",
    "ResponseDescription": "Approved by Financial Institution",
    "AccountNumber": "9999999999"
}
JSON;

/**
 * Webhook body copied from the webhooks page on docs.interswitchgroup.com.
 */
const INTERSWITCH_WEBHOOK_SAMPLE = <<<'JSON'
{
 "event": "TRANSACTION.COMPLETED",
 "uuid": "2Xdf35faAyX2Sk5Dalu405rUD",
 "timestamp": 1594646111460,
 "data": {
 "remittanceAmount": 0,
 "bankCode": "011",
 "amount": 12000,
 "paymentReference": "FBN|WEB|MX6072|13-07-2020|3481032|762672",
 "channel": "WEB",
 "splitAccounts": [],
 "retrievalReferenceNumber": "000106923853",
 "transactionDate": 1594646111460,
 "accountNumber": null,
 "responseCode": "00",
 "token": null,
 "responseDescription": "Approved by Financial Institution",
 "paymentId": 3481032,
 "merchantCustomerId": "hhs@gr.la",
 "escrow": false,
 "merchantReference": "2Xdf35faAyX2Sk5Dalu405rUD",
 "currencyCode": "566",
 "merchantCustomerName": "yee tod",
 "cardNumber": "561233*********0865"
 }
}
JSON;

function interswitch_ready(): void
{
    config([
        'payments.provider' => 'interswitch',
        'payments.interswitch.mode' => 'TEST',
        'payments.interswitch.merchant_code' => 'MTEST',
        'payments.interswitch.pay_item_id' => 'PITEM',
        'payments.interswitch.client_id' => '',
        'payments.interswitch.secret' => '',
        'payments.interswitch.webhook_secret' => 'webhook-test-secret',
    ]);
}

/**
 * @return array{user: User, invoice: Invoice, payment: Payment}
 */
function interswitch_payment(string $reference, int $amount_kobo): array
{
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->create([
        'student_id' => $student->id,
        'total_kobo' => $amount_kobo,
        'paid_kobo' => 0,
        'adjustments_kobo' => 0,
        'status' => InvoiceStatus::Unpaid,
    ]);
    $payment = Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'student_id' => $student->id,
        'reference' => $reference,
        'amount_kobo' => $amount_kobo,
        'gateway' => PaymentGateway::Interswitch,
        'provider_reference' => null,
        'status' => PaymentStatus::Pending,
        'expires_at' => now()->addMinutes(30),
    ]);

    return [
        'user' => $user,
        'invoice' => $invoice,
        'payment' => $payment,
    ];
}

/**
 * The documented requery sample with one response code from the response-codes page.
 */
function interswitch_sample(string $response_code, string $reference, ?string $currency = null): string
{
    $payload = json_decode(INTERSWITCH_REQUERY_SAMPLE, true, 512, JSON_THROW_ON_ERROR);
    $payload['ResponseCode'] = $response_code;
    $payload['MerchantReference'] = $reference;

    if ($currency !== null) {
        $payload['currencyCode'] = $currency;
    }

    return json_encode($payload, JSON_THROW_ON_ERROR);
}

it('settles the documented requery sample and ignores the redirect body', function () {
    interswitch_ready();
    Http::preventStrayRequests();
    Http::fake([
        'https://sandbox.interswitchng.com/collections/api/v1/gettransaction.json*' => Http::response(INTERSWITCH_REQUERY_SAMPLE, 200),
    ]);
    $world = interswitch_payment('933437251484', 66666600);
    $secret = 'planted-redirect-secret-9f3c';
    $logged = '';
    Log::listen(function (MessageLogged $event) use (&$logged): void {
        $logged .= $event->message.json_encode($event->context);
    });

    $response = $this->post('/payments/return', [
        'txnref' => '933437251484',
        'amount' => '1',
        'resp' => '00',
        'desc' => $secret,
    ]);
    $payment = $world['payment']->fresh();
    $stored = json_encode(PaymentEvent::query()->get()->toArray());
    $options = app(InterswitchGateway::class)->clientOptions();

    $response->assertRedirect(route('student.payments.show', ['reference' => '933437251484']));

    expect($payment?->status)->toBe(PaymentStatus::Successful)
        ->and($payment?->provider_reference)->toBe('UBA|API|MX187|25-07-2018|249510|719456')
        ->and((int) $world['invoice']->fresh()?->paid_kobo)->toBe(66666600)
        ->and($payment?->receipt()->exists())->toBeTrue()
        ->and($options['verify'])->toBeTrue()
        ->and($options['timeout'])->toBe(5)
        ->and($options['allow_redirects'])->toBeFalse()
        ->and($options['http_errors'])->toBeFalse()
        ->and(str_contains((string) $stored, 'Approved by Financial Institution'))->toBeFalse()
        ->and(str_contains((string) $stored, '9999999999'))->toBeFalse()
        ->and(str_contains($logged, $secret))->toBeFalse()
        ->and(str_contains((string) json_encode($payment?->toArray()), $secret))->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://sandbox.interswitchng.com/collections/api/v1/gettransaction.json')
            && ! $request->hasHeader('Authorization')
            && $request->data()['merchantcode'] === 'MTEST'
            && $request->data()['transactionreference'] === '933437251484'
            && (int) $request->data()['amount'] === 66666600;
    });
    expect(count(Http::recorded()))->toBe(1);
});

it('keeps a provider error body out of logs, events, exceptions, and failed jobs', function () {
    interswitch_ready();
    Http::preventStrayRequests();
    $secret = 'planted-provider-secret-9f3c';
    Http::fake([
        'https://sandbox.interswitchng.com/*' => Http::response('{"message":"'.$secret.'"}', 500),
    ]);
    $world = interswitch_payment('P20260000000051', 100_000);
    $logged = '';
    Log::listen(function (MessageLogged $event) use (&$logged): void {
        $logged .= $event->message.json_encode($event->context);
    });
    $thrown = '';

    try {
        app(PaymentService::class)->verifyAndSettle($world['payment']->reference, PaymentEventSource::Poll->value);
    } catch (Throwable $exception) {
        $thrown = $exception->getMessage();
    }

    $payment = $world['payment']->fresh();
    $blob = $logged.$thrown.json_encode(PaymentEvent::query()->get()->toArray());
    $blob .= (string) DB::table('failed_jobs')->get();
    $blob .= (string) DB::table('jobs')->get();

    expect($payment?->status)->toBe(PaymentStatus::Pending)
        ->and(InterswitchGateway::errorCode(500, false))->toBe(InterswitchGateway::ERROR_HTTP)
        ->and(InterswitchGateway::errorCode(200, true))->toBe(InterswitchGateway::ERROR_BODY)
        ->and(str_contains($blob, $secret))->toBeFalse()
        ->and(count(Http::recorded()))->toBe(1);
});

it('does not credit response codes 10 or 11, a decline, or the unresolved codes', function (string $response_code, string $expected) {
    interswitch_ready();
    Http::preventStrayRequests();
    $reference = 'P2026'.$response_code;
    $reference = preg_replace('/[^A-Za-z0-9]/', '', $reference) ?? $reference;
    Http::fake([
        'https://sandbox.interswitchng.com/*' => Http::response(interswitch_sample($response_code, $reference), 200),
    ]);
    $world = interswitch_payment($reference, 66666600);

    app(PaymentService::class)->verifyAndSettle($reference, PaymentEventSource::Poll->value);

    expect($world['payment']->fresh()?->status->value)->toBe($expected)
        ->and((int) $world['invoice']->fresh()?->paid_kobo)->toBe(0);
})->with([
    ['10', 'Pending'],
    ['11', 'Pending'],
    ['09', 'Pending'],
    ['51', 'Failed'],
    ['Z6', 'Pending'],
    ['Z25', 'Pending'],
]);

it('does not credit a currency other than 566', function () {
    interswitch_ready();
    Http::preventStrayRequests();
    $reference = 'P20260000000566';
    $secret = 'planted-currency-secret-9f3c';
    Http::fake([
        'https://sandbox.interswitchng.com/*' => Http::response(interswitch_sample('00', $reference, $secret), 200),
    ]);
    $world = interswitch_payment($reference, 66666600);

    app(PaymentService::class)->verifyAndSettle($reference, PaymentEventSource::Poll->value);
    $stored = json_encode([
        $world['payment']->fresh()?->toArray(),
        PaymentEvent::query()->get()->toArray(),
    ]);

    expect($world['payment']->fresh()?->status)->toBe(PaymentStatus::Pending)
        ->and(str_contains((string) $stored, $secret))->toBeFalse();
});

it('uses the live requery host when the mode is LIVE', function () {
    interswitch_ready();
    config(['payments.interswitch.mode' => 'LIVE']);
    Http::preventStrayRequests();
    Http::fake([
        'https://webpay.interswitchng.com/*' => Http::response(INTERSWITCH_REQUERY_SAMPLE, 200),
    ]);
    interswitch_payment('933437251484', 66666600);

    app(InterswitchGateway::class)->verify('933437251484');

    Http::assertSent(function (Request $request): bool {
        return str_starts_with($request->url(), 'https://webpay.interswitchng.com/collections/api/v1/gettransaction.json');
    });
});

it('posts the documented checkout form without a third-party script', function () {
    interswitch_ready();
    config(['payments.interswitch.secret' => 'super-secret-value', 'payments.interswitch.client_id' => 'client-secret-value']);
    $world = interswitch_payment('P20260000000077', 100_000);

    $page = $this->actingAs($world['user'])->get('/student/payments/interswitch/'.$world['payment']->reference);
    $html = (string) $page->getContent();

    $page->assertOk();
    expect(str_contains($html, 'https://newwebpay-sandbox.interswitchng.com/collections/w/pay'))->toBeTrue()
        ->and(str_contains($html, 'method="post"'))->toBeTrue()
        ->and(str_contains($html, 'name="merchant_code"'))->toBeTrue()
        ->and(str_contains($html, 'value="MTEST"'))->toBeTrue()
        ->and(str_contains($html, 'name="pay_item_id"'))->toBeTrue()
        ->and(str_contains($html, 'name="txn_ref"'))->toBeTrue()
        ->and(str_contains($html, 'name="amount"'))->toBeTrue()
        ->and(str_contains($html, 'value="100000"'))->toBeTrue()
        ->and(str_contains($html, 'name="currency"'))->toBeTrue()
        ->and(str_contains($html, 'value="566"'))->toBeTrue()
        ->and(str_contains($html, 'name="cust_email"'))->toBeTrue()
        ->and(str_contains($html, 'name="site_redirect_url"'))->toBeTrue()
        ->and(str_contains($html, '/payments/return'))->toBeTrue()
        ->and(str_contains($html, 'name="payment_response_type"'))->toBeTrue()
        ->and(str_contains($html, 'Make Payment'))->toBeTrue()
        ->and(str_contains($html, "document.getElementById('interswitch-checkout').submit()"))->toBeTrue()
        ->and(str_contains($html, 'inline-checkout.js'))->toBeFalse()
        ->and(str_contains($html, 'name="hash"'))->toBeFalse()
        ->and(str_contains($html, 'super-secret-value'))->toBeFalse()
        ->and(str_contains($html, 'client-secret-value'))->toBeFalse()
        ->and(preg_match('/<script[^>]+src=["\'][^"\']*interswitch/i', $html))->not->toBe(1);

    $other = User::factory()->create();
    Student::factory()->create(['user_id' => $other->id]);
    $foreign = $this->actingAs($other)->get('/student/payments/interswitch/'.$world['payment']->reference);
    $missing = $this->actingAs($other)->get('/student/payments/interswitch/P20260000000999');

    expect($foreign->status())->toBe(404)
        ->and($missing->status())->toBe(404);
});

it('reads a signed webhook reference and ignores an unsigned body', function () {
    interswitch_ready();
    Http::preventStrayRequests();
    Http::fake([
        'https://sandbox.interswitchng.com/*' => Http::response(INTERSWITCH_REQUERY_SAMPLE, 200),
    ]);
    $world = interswitch_payment('2Xdf35faAyX2Sk5Dalu405rUD', 12000);
    $secret = 'planted-webhook-secret-9f3c';
    $signed = hash_hmac('sha512', INTERSWITCH_WEBHOOK_SAMPLE, 'webhook-test-secret');
    $logged = '';
    Log::listen(function (MessageLogged $event) use (&$logged): void {
        $logged .= $event->message.json_encode($event->context);
    });

    $rejected = $this->call('POST', '/payments/notify/interswitch', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_INTERSWITCH_SIGNATURE' => hash_hmac('sha512', INTERSWITCH_WEBHOOK_SAMPLE, 'wrong-secret'),
    ], INTERSWITCH_WEBHOOK_SAMPLE);
    $unsigned = $this->call('POST', '/payments/notify/interswitch', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], '{"data":{"merchantReference":"2Xdf35faAyX2Sk5Dalu405rUD","responseDescription":"'.$secret.'"}}');
    $accepted = $this->call('POST', '/payments/notify/interswitch', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_INTERSWITCH_SIGNATURE' => $signed,
    ], INTERSWITCH_WEBHOOK_SAMPLE);

    $stored = json_encode($world['payment']->fresh()?->toArray()).json_encode(PaymentEvent::query()->get()->toArray());

    expect($rejected->getStatusCode())->toBe(200)
        ->and($rejected->getContent())->toBe('')
        ->and($unsigned->getContent())->toBe('')
        ->and($accepted->getStatusCode())->toBe(200)
        ->and($accepted->getContent())->toBe('')
        ->and((int) $world['invoice']->fresh()?->paid_kobo)->toBe(0)
        ->and(str_contains($logged.$stored, $secret))->toBeFalse()
        ->and(str_contains($logged.$stored, '561233'))->toBeFalse()
        ->and(count(Http::recorded()))->toBe(1);
});
