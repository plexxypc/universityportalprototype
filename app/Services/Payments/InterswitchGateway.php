<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Exceptions\PaymentsUnavailableException;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Interswitch Web Checkout redirect and the documented requery.
 *
 * Built from the Web Checkout page, the Web Checkout quickstart, the
 * payment response codes, and the webhooks page on
 * docs.interswitchgroup.com. A field those pages do not define is not sent
 * and is not read. No call is retried.
 */
final class InterswitchGateway implements PaymentGateway
{
    public const string ERROR_HTTP = 'provider_http';

    public const string ERROR_BODY = 'provider_body';

    private const string SANDBOX_PAY = 'https://newwebpay-sandbox.interswitchng.com/collections/w/pay';

    private const string LIVE_PAY = 'https://newwebpay.interswitchng.com/collections/w/pay';

    private const string SANDBOX_REQUERY = 'https://sandbox.interswitchng.com/collections/api/v1/gettransaction.json';

    private const string LIVE_REQUERY = 'https://webpay.interswitchng.com/collections/api/v1/gettransaction.json';

    /**
     * Response codes the payment-response-codes page marks Declined.
     *
     * 10 and 11 are documented as Successful and are not credited.
     * Z6 is both Cancelled and Declined on that page, so it stays pending.
     * Z25 is Declined on that page and stays pending because ADR-032 says so.
     *
     * @var list<string>
     */
    private const array DECLINED = [
        '01', '02', '03', '04', '05', '06', '07', '08',
        '12', '13', '14', '15', '17', '18', '19', '20',
        '21', '22', '23', '24', '25', '26', '27', '28', '29', '30',
        '31', '32', '33', '34', '36', '38', '39', '40', '41', '42',
        '43', '44', '45', '46', '47', '48', '51', '52', '53', '54',
        '55', '56', '57', '58', '59', '60', '61', '62', '63', '64',
        '65', '66', '67', '68', '75', '76', '77', '78', '79', '81',
        '82', '83', '86', '88', '89', '90', '91', '92', '93', '94',
        '95', '96', '97', '98', '99', 'A0', 'A4', 'A8', 'A9', 'E21',
        'E42', 'E53', 'E60', 'F4', 'F5', 'G3', 'G4', 'H4', 'S3', 'SK',
        'X03', 'X04', 'X05', 'XGO', 'XG1', 'XS1', 'XX', 'Z1', 'Z2',
        'Z3', 'Z4', 'Z5', 'Z7', 'Z8', 'Z162',
    ];

    /**
     * Checkout page on this portal. The page posts the documented form.
     */
    public function initialize(Payment $payment): string
    {
        $this->checkout($payment);

        return route('student.payments.interswitch', ['reference' => $payment->reference]);
    }

    /**
     * Documented redirect form. The action is HTTPS. No hash field is added.
     *
     * @return array{action: string, fields: array<string, string>}
     */
    public function checkout(Payment $payment): array
    {
        if (! $this->ready()) {
            throw new PaymentsUnavailableException;
        }

        $payment->loadMissing('student.user');
        $email = $payment->student?->user?->email;

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new PaymentsUnavailableException;
        }

        $action = $this->payUrl();

        if (preg_match('#\Ahttps://#', $action) !== 1) {
            throw new PaymentsUnavailableException;
        }

        return [
            'action' => $action,
            'fields' => [
                'merchant_code' => $this->merchantCode(),
                'pay_item_id' => $this->payItemId(),
                'txn_ref' => $payment->reference,
                'amount' => (string) $payment->amount_kobo,
                'currency' => '566',
                'cust_email' => $email,
                'site_redirect_url' => route('payments.return', absolute: true),
                'payment_response_type' => 'POST',
            ],
        ];
    }

    /**
     * Requery this reference. The outcome uses only the whitelisted fields.
     */
    public function verify(string $reference): GatewayOutcome
    {
        if (preg_match('/\A[A-Za-z0-9]{1,64}\z/', $reference) !== 1 || ! $this->ready()) {
            return $this->pending($reference);
        }

        $payment = Payment::query()->where('reference', $reference)->first();

        if (! $payment instanceof Payment) {
            return $this->pending($reference);
        }

        $url = $this->requeryUrl();

        if (preg_match('#\Ahttps://#', $url) !== 1) {
            return $this->pending($reference);
        }

        try {
            $response = Http::withOptions($this->clientOptions())
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->get($url, [
                    'merchantcode' => $this->merchantCode(),
                    'transactionreference' => $reference,
                    'amount' => (int) $payment->amount_kobo,
                ]);
        } catch (Throwable) {
            return $this->pending($reference);
        }

        if ($response->status() !== 200) {
            return $this->pending($reference);
        }

        $decoded = json_decode($response->body(), true);

        if (! is_array($decoded)) {
            return $this->pending($reference);
        }

        return $this->outcomeFromPayload($reference, $decoded);
    }

    /**
     * Merchant reference from a signed webhook, or null.
     *
     * The webhooks page signs the raw body with HMAC-SHA512. The secret is
     * the webhook configuration secret, not the client secret. Amount and
     * response code in the body are ignored.
     */
    public function parseNotification(string $body, ?string $signature = null): ?string
    {
        if (strlen($body) > (int) config('payments.notification_max_bytes')) {
            return null;
        }

        $secret = config('payments.interswitch.webhook_secret');

        if (! is_string($secret) || $secret === '' || ! is_string($signature)) {
            return null;
        }

        $given = strtolower(trim($signature));

        if (preg_match('/\A[0-9a-f]{128}\z/', $given) !== 1) {
            return null;
        }

        $expected = hash_hmac('sha512', $body, $secret);

        if (! hash_equals($expected, $given)) {
            return null;
        }

        $decoded = json_decode($body, true);
        $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;
        $reference = is_array($data) ? ($data['merchantReference'] ?? null) : null;

        if (! is_string($reference) || preg_match('/\A[A-Za-z0-9]{1,64}\z/', $reference) !== 1) {
            return null;
        }

        return $reference;
    }

    /**
     * HTTP options for one requery. TLS stays on. Nothing is retried.
     *
     * @return array{verify: true, timeout: int, connect_timeout: int, allow_redirects: false, http_errors: false}
     */
    public function clientOptions(): array
    {
        $timeout = (int) config('payments.http_timeout_seconds');

        if ($timeout < 1 || $timeout > 10) {
            $timeout = 5;
        }

        return [
            'verify' => true,
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'allow_redirects' => false,
            'http_errors' => false,
        ];
    }

    /**
     * A fixed code for a provider failure. The provider body is not included.
     */
    public static function errorCode(int $status, bool $body_unreadable): string
    {
        if ($status !== 200) {
            return self::ERROR_HTTP;
        }

        if ($body_unreadable) {
            return self::ERROR_BODY;
        }

        return 'provider_ok';
    }

    /**
     * A pending outcome. No provider text is attached.
     */
    private function pending(string $reference): GatewayOutcome
    {
        return new GatewayOutcome($reference, 0, null, 'pending', null);
    }

    /**
     * Whitelisted requery fields. Every other key is discarded.
     */
    private function outcomeFromPayload(string $reference, mixed $payload): GatewayOutcome
    {
        if (! is_array($payload)) {
            return $this->pending($reference);
        }

        $amount = $payload['Amount'] ?? null;
        $merchant_reference = $payload['MerchantReference'] ?? null;
        $response_code = $payload['ResponseCode'] ?? null;
        $provider_reference = $this->providerReference($payload['PaymentReference'] ?? null);
        $currency = $this->currency($payload);

        if (! is_int($amount) || ! is_string($response_code)) {
            return new GatewayOutcome($reference, 0, null, 'pending', $provider_reference);
        }

        if ($currency === 'rejected') {
            return new GatewayOutcome($reference, $amount, null, 'pending', $provider_reference);
        }

        $result = $this->resultFor($response_code);

        if ($result !== 'success') {
            return new GatewayOutcome($reference, $amount, $currency, $result, $provider_reference);
        }

        if (! is_string($merchant_reference) || $merchant_reference !== $reference) {
            return new GatewayOutcome($reference, $amount, $currency, 'pending', $provider_reference);
        }

        return new GatewayOutcome($reference, $amount, $currency, 'success', $provider_reference);
    }

    /**
     * 566 when the sample omitted currency or sent 566. rejected otherwise.
     *
     * @param  array<string, mixed>  $payload
     */
    private function currency(array $payload): ?string
    {
        $values = [];

        foreach (['Currency', 'currency', 'currencyCode'] as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
                continue;
            }

            $raw = $payload[$key];
            $values[] = is_int($raw) || is_string($raw) ? (string) $raw : 'rejected';
        }

        if ($values === []) {
            return null;
        }

        foreach ($values as $value) {
            if ($value !== '566') {
                return 'rejected';
            }
        }

        return '566';
    }

    /**
     * PaymentReference when it fits the documented sample shape.
     */
    private function providerReference(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A[A-Za-z0-9|-]{1,64}\z/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * success, failed, or pending. Only 00 is success.
     */
    private function resultFor(string $response_code): string
    {
        if ($response_code === '00') {
            return 'success';
        }

        if (in_array($response_code, self::DECLINED, true)) {
            return 'failed';
        }

        return 'pending';
    }

    private function ready(): bool
    {
        return app(PaymentGatewayResolver::class)->interswitchReady();
    }

    private function payUrl(): string
    {
        return $this->mode() === 'LIVE' ? self::LIVE_PAY : self::SANDBOX_PAY;
    }

    private function requeryUrl(): string
    {
        return $this->mode() === 'LIVE' ? self::LIVE_REQUERY : self::SANDBOX_REQUERY;
    }

    private function mode(): string
    {
        return strtoupper(trim((string) config('payments.interswitch.mode')));
    }

    private function merchantCode(): string
    {
        return trim((string) config('payments.interswitch.merchant_code'));
    }

    private function payItemId(): string
    {
        return trim((string) config('payments.interswitch.pay_item_id'));
    }
}
