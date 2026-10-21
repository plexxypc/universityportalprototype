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
function interswitch_sample(
    string $response_code,
    string $reference,
    ?string $currency = null,
    ?int $amount_kobo = null,
    ?string $merchant_reference = null,
): string {
    $payload = json_decode(INTERSWITCH_REQUERY_SAMPLE, true, 512, JSON_THROW_ON_ERROR);
    $payload['ResponseCode'] = $response_code;
    $payload['MerchantReference'] = $merchant_reference ?? $reference;

    if ($amount_kobo !== null) {
        $payload['Amount'] = $amount_kobo;
    }

    if ($currency !== null) {
        $payload['currencyCode'] = $currency;
    }

    return json_encode($payload, JSON_THROW_ON_ERROR);
}

/**
 * Requery one reference against a faked body and return the fresh payment.
 */
function interswitch_requery(string $reference, string $body, int $amount_kobo): Payment
{
    Http::preventStrayRequests();
    Http::fake([
        'https://sandbox.interswitchng.com/*' => Http::response($body, 200),
    ]);
    $world = interswitch_payment($reference, $amount_kobo);
    app(PaymentService::class)->verifyAndSettle($reference, PaymentEventSource::Poll->value);

    return $world['payment']->fresh() ?? $world['payment'];
}

it('accepts response code 00 when the amount matches exactly and currency is absent', function () {
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

it('maps response code 09 to pending without a review', function () {
    interswitch_ready();
    $payment = interswitch_requery('P20260000000009', interswitch_sample('09', 'P20260000000009'), 66666600);

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and(app(PaymentService::class)->underReview($payment))->toBeFalse()
        ->and((int) $payment->invoice->fresh()?->paid_kobo)->toBe(0);
});

it('maps response code Z25 to pending without a review', function () {
    interswitch_ready();
    $payment = interswitch_requery('P20260000000Z25', interswitch_sample('Z25', 'P20260000000Z25'), 66666600);

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and(app(PaymentService::class)->underReview($payment))->toBeFalse();
});

it('maps documented declined code 51 to failed', function () {
    interswitch_ready();
    $payment = interswitch_requery('P20260000000051', interswitch_sample('51', 'P20260000000051'), 66666600);

    expect($payment->status)->toBe(PaymentStatus::Failed)
        ->and(app(PaymentService::class)->underReview($payment))->toBeFalse()
        ->and((int) $payment->invoice->fresh()?->paid_kobo)->toBe(0);
});

it('maps response code 10 to pending with a review', function () {
    interswitch_ready();
    $payment = interswitch_requery('P20260000000010', interswitch_sample('10', 'P20260000000010'), 66666600);

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and(app(PaymentService::class)->underReview($payment))->toBeTrue()
        ->and((int) $payment->invoice->fresh()?->paid_kobo)->toBe(0);
});

it('maps response code 11 to pending with a review', function () {
    interswitch_ready();
    $payment = interswitch_requery('P20260000000011', interswitch_sample('11', 'P20260000000011'), 66666600);

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and(app(PaymentService::class)->underReview($payment))->toBeTrue();
});

it('maps response code Z6 to pending without a review', function () {
    interswitch_ready();
    $payment = interswitch_requery('P202600000000Z6', interswitch_sample('Z6', 'P202600000000Z6'), 66666600);

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and(app(PaymentService::class)->underReview($payment))->toBeFalse();
});

it('maps an unlisted response code to pending with a review', function () {
    interswitch_ready();
    $payment = interswitch_requery('P202600000000Q1', interswitch_sample('Q1', 'P202600000000Q1'), 66666600);

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and(app(PaymentService::class)->underReview($payment))->toBeTrue()
        ->and((int) $payment->invoice->fresh()?->paid_kobo)->toBe(0);
});

it('holds an amount mismatch for review', function () {
    interswitch_ready();
    $reference = 'P20260000000AMT';
    $payment = interswitch_requery($reference, interswitch_sample('00', $reference, null, 100_000), 66666600);

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and(app(PaymentService::class)->underReview($payment))->toBeTrue()
        ->and((int) $payment->invoice->fresh()?->paid_kobo)->toBe(0);
});

it('holds a MerchantReference mismatch for review', function () {
    interswitch_ready();
    $reference = 'P20260000000REF';
    $payment = interswitch_requery(
        $reference,
        interswitch_sample('00', $reference, null, 66666600, 'OTHERREFERENCE1'),
        66666600,
    );

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and(app(PaymentService::class)->underReview($payment))->toBeTrue()
        ->and((int) $payment->invoice->fresh()?->paid_kobo)->toBe(0);
});

it('refuses a currency that is present and is not 566', function () {
    interswitch_ready();
    $reference = 'P20260000000566';
    $secret = 'planted-currency-secret-9f3c';
    $payment = interswitch_requery($reference, interswitch_sample('00', $reference, $secret), 66666600);
    $stored = json_encode([
        $payment->toArray(),
        PaymentEvent::query()->get()->toArray(),
    ]);

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and(app(PaymentService::class)->underReview($payment))->toBeTrue()
        ->and(str_contains((string) $stored, $secret))->toBeFalse();
});

it('counts code 10, code 11, an unlisted code, and a mismatch in the same review list as an overpayment', function () {
    interswitch_ready();
    Http::preventStrayRequests();
    $bodies = [
        'P20260000000010' => interswitch_sample('10', 'P20260000000010'),
        'P20260000000011' => interswitch_sample('11', 'P20260000000011'),
        'P202600000000Q1' => interswitch_sample('Q1', 'P202600000000Q1'),
        'P20260000000MISS' => interswitch_sample('00', 'P20260000000MISS', null, 100_000),
        'P20260000000OVER' => interswitch_sample('00', 'P20260000000OVER', null, 200_000),
    ];
    Http::fake(function (Request $request) use ($bodies) {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $reference = (string) ($query['transactionreference'] ?? '');
        $payload = json_decode($bodies[$reference] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        unset($payload['PaymentReference']);

        return Http::response(json_encode($payload, JSON_THROW_ON_ERROR), 200);
    });
    interswitch_payment('P20260000000010', 66666600);
    interswitch_payment('P20260000000011', 66666600);
    interswitch_payment('P202600000000Q1', 66666600);
    interswitch_payment('P20260000000MISS', 66666600);
    $overpayment = interswitch_payment('P20260000000OVER', 200_000);
    $overpayment['invoice']->total_kobo = 100_000;
    $overpayment['invoice']->save();
    $service = app(PaymentService::class);

    foreach (array_keys($bodies) as $reference) {
        $service->verifyAndSettle($reference, PaymentEventSource::Poll->value);
    }

    $note = view('filament.staff.invoices-note')->render();

    expect($service->openReviewCount())->toBe(5)
        ->and($note)->toContain('Payments under review: 5')
        ->and($service->underReview($overpayment['payment']->fresh() ?? $overpayment['payment']))->toBeTrue();
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

it('posts only the documented checkout fields', function () {
    interswitch_ready();
    config(['payments.interswitch.secret' => 'super-secret-value', 'payments.interswitch.client_id' => 'client-secret-value']);
    $world = interswitch_payment('P20260000000077', 100_000);

    $page = $this->actingAs($world['user'])->get('/student/payments/interswitch/'.$world['payment']->reference);
    $html = (string) $page->getContent();

    $page->assertOk();
    preg_match('/<form id="interswitch-checkout"[^>]*>.*?<\/form>/s', $html, $form);
    preg_match_all('/<input type="hidden" name="([^"]+)"/', $form[0] ?? '', $names);

    expect($names[1])->toBe([
        'merchant_code',
        'pay_item_id',
        'txn_ref',
        'amount',
        'currency',
        'cust_email',
        'site_redirect_url',
    ])
        ->and(str_contains($html, 'https://newwebpay-sandbox.interswitchng.com/collections/w/pay'))->toBeTrue()
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
        ->and(str_contains($html, 'name="payment_response_type"'))->toBeFalse()
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

it('calls the requery over HTTPS with TLS verification and a short timeout', function () {
    interswitch_ready();
    Http::preventStrayRequests();
    Http::fake([
        'https://sandbox.interswitchng.com/*' => Http::response(INTERSWITCH_REQUERY_SAMPLE, 200),
    ]);
    interswitch_payment('933437251484', 66666600);
    $options = app(InterswitchGateway::class)->clientOptions();

    app(InterswitchGateway::class)->verify('933437251484');

    expect($options['verify'])->toBeTrue()
        ->and($options['timeout'])->toBeGreaterThanOrEqual(1)
        ->and($options['timeout'])->toBeLessThanOrEqual(10)
        ->and($options['connect_timeout'])->toBe($options['timeout'])
        ->and($options['allow_redirects'])->toBeFalse()
        ->and($options['http_errors'])->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET' && str_starts_with($request->url(), 'https://');
    });

    $plain = collect(Http::recorded())->contains(
        fn (array $pair): bool => str_starts_with($pair[0]->url(), 'http://'),
    );

    expect($plain)->toBeFalse();
});

it('ignores a webhook with no signature', function () {
    interswitch_ready();
    Http::preventStrayRequests();
    Http::fake(['https://sandbox.interswitchng.com/*' => Http::response(INTERSWITCH_REQUERY_SAMPLE, 200)]);
    interswitch_payment('2Xdf35faAyX2Sk5Dalu405rUD', 12000);
    $secret = 'planted-webhook-secret-9f3c';
    $logged = '';
    Log::listen(function (MessageLogged $event) use (&$logged): void {
        $logged .= $event->message.json_encode($event->context);
    });

    $response = $this->call('POST', '/payments/notify/interswitch', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], '{"data":{"merchantReference":"2Xdf35faAyX2Sk5Dalu405rUD","responseDescription":"'.$secret.'"}}');

    $stored = json_encode(PaymentEvent::query()->get()->toArray());

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('')
        ->and(str_contains($logged.$stored, $secret))->toBeFalse()
        ->and(count(Http::recorded()))->toBe(0);
});

it('ignores a webhook with a bad signature', function () {
    interswitch_ready();
    Http::preventStrayRequests();
    Http::fake(['https://sandbox.interswitchng.com/*' => Http::response(INTERSWITCH_REQUERY_SAMPLE, 200)]);
    interswitch_payment('2Xdf35faAyX2Sk5Dalu405rUD', 12000);

    $response = $this->call('POST', '/payments/notify/interswitch', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_INTERSWITCH_SIGNATURE' => hash_hmac('sha512', INTERSWITCH_WEBHOOK_SAMPLE, 'wrong-secret'),
    ], INTERSWITCH_WEBHOOK_SAMPLE);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('')
        ->and(count(Http::recorded()))->toBe(0);
});

it('requeries a known merchantReference from a valid signature', function () {
    interswitch_ready();
    Http::preventStrayRequests();
    Http::fake(['https://sandbox.interswitchng.com/*' => Http::response(INTERSWITCH_REQUERY_SAMPLE, 200)]);
    interswitch_payment('2Xdf35faAyX2Sk5Dalu405rUD', 12000);
    $signed = hash_hmac('sha512', INTERSWITCH_WEBHOOK_SAMPLE, 'webhook-test-secret');

    $response = $this->call('POST', '/payments/notify/interswitch', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_INTERSWITCH_SIGNATURE' => $signed,
    ], INTERSWITCH_WEBHOOK_SAMPLE);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'transactionreference=2Xdf35faAyX2Sk5Dalu405rUD');
    });
    expect(count(Http::recorded()))->toBe(1);
});

it('does not credit an unknown merchantReference from a valid signature', function () {
    interswitch_ready();
    Http::preventStrayRequests();
    Http::fake(['https://sandbox.interswitchng.com/*' => Http::response(INTERSWITCH_REQUERY_SAMPLE, 200)]);
    $signed = hash_hmac('sha512', INTERSWITCH_WEBHOOK_SAMPLE, 'webhook-test-secret');
    $before = Payment::query()->count();

    $response = $this->call('POST', '/payments/notify/interswitch', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_INTERSWITCH_SIGNATURE' => $signed,
    ], INTERSWITCH_WEBHOOK_SAMPLE);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('')
        ->and(Payment::query()->count())->toBe($before)
        ->and(count(Http::recorded()))->toBe(0);
});

it('ignores a webhook when the webhook secret is unset', function () {
    interswitch_ready();
    config(['payments.interswitch.webhook_secret' => '']);
    Http::preventStrayRequests();
    Http::fake(['https://sandbox.interswitchng.com/*' => Http::response(INTERSWITCH_REQUERY_SAMPLE, 200)]);
    interswitch_payment('2Xdf35faAyX2Sk5Dalu405rUD', 12000);
    $signed = hash_hmac('sha512', INTERSWITCH_WEBHOOK_SAMPLE, 'webhook-test-secret');

    $response = $this->call('POST', '/payments/notify/interswitch', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_INTERSWITCH_SIGNATURE' => $signed,
    ], INTERSWITCH_WEBHOOK_SAMPLE);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('')
        ->and(count(Http::recorded()))->toBe(0);
});

it('compares the webhook signature in constant time', function () {
    $source = (string) file_get_contents(app_path('Services/Payments/InterswitchGateway.php'));
    $start = strpos($source, 'function signatureMatches');
    $end = strpos($source, 'function clientOptions');
    $method = substr($source, (int) $start, (int) $end - (int) $start);
    $expected = hash_hmac('sha512', 'body', 'secret');

    expect(str_contains($method, 'hash_equals('))->toBeTrue()
        ->and(str_contains($method, ' === '))->toBeFalse()
        ->and(str_contains($method, ' == '))->toBeFalse()
        ->and(InterswitchGateway::signatureMatches($expected, $expected))->toBeTrue()
        ->and(InterswitchGateway::signatureMatches($expected, str_repeat('ab', 64)))->toBeFalse()
        ->and(InterswitchGateway::signatureMatches($expected, 'ab'))->toBeFalse();
});
