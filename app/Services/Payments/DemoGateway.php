<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Cache;

/**
 * Local checkout. No money moves and no provider is called.
 *
 * A signed outcome is stored only after the student submits a valid
 * signature. verify reads that store. A missing outcome stays pending.
 */
final class DemoGateway implements PaymentGateway
{
    public const string BANNER = 'Demo payment: no money moves.';

    /**
     * @var list<string>
     */
    public const array OUTCOMES = ['success', 'failed', 'cancelled'];

    /**
     * Checkout page for this payment.
     */
    public function initialize(Payment $payment): string
    {
        return route('student.payments.demo', ['reference' => $payment->reference]);
    }

    /**
     * The outcome stored for this reference, or pending when there is none.
     */
    public function verify(string $reference): GatewayOutcome
    {
        $stored = Cache::get(self::cacheKey($reference));

        if (! is_array($stored)) {
            return new GatewayOutcome($reference, 0, null, 'pending', null);
        }

        $result = is_string($stored['result'] ?? null) ? $stored['result'] : 'pending';
        $amount_kobo = is_int($stored['amount_kobo'] ?? null) ? $stored['amount_kobo'] : 0;
        $currency = is_string($stored['currency'] ?? null) ? $stored['currency'] : null;
        $provider_reference = is_string($stored['provider_reference'] ?? null)
            ? $stored['provider_reference']
            : null;

        return new GatewayOutcome($reference, $amount_kobo, $currency, $result, $provider_reference);
    }

    /**
     * Our reference, when the body is a small JSON object that has one.
     *
     * Amount, status, and every other field are ignored.
     */
    public function parseNotification(string $body): ?string
    {
        if (strlen($body) > (int) config('payments.notification_max_bytes')) {
            return null;
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return null;
        }

        $reference = $decoded['reference'] ?? null;

        if (! is_string($reference) || preg_match('/\A[A-Za-z0-9]{1,64}\z/', $reference) !== 1) {
            return null;
        }

        return $reference;
    }

    /**
     * Signature for one allowed outcome. The key stays in the application.
     */
    public function signature(string $reference, int $amount_kobo, string $outcome): string
    {
        return hash_hmac('sha256', $reference.'|'.$amount_kobo.'|'.$outcome, $this->key());
    }

    /**
     * Whether this signature matches the reference, amount, and outcome.
     */
    public function signatureMatches(string $reference, int $amount_kobo, string $outcome, string $signature): bool
    {
        $expected = $this->signature($reference, $amount_kobo, $outcome);

        return hash_equals($expected, $signature);
    }

    /**
     * Remember a signed outcome. verify reads it later.
     */
    public function remember(string $reference, int $amount_kobo, string $outcome): void
    {
        Cache::put(self::cacheKey($reference), [
            'result' => $outcome,
            'amount_kobo' => $amount_kobo,
            'currency' => '566',
            'provider_reference' => 'demo-'.$reference,
        ], now()->addHour());
    }

    /**
     * Cache key for one reference. The reference is not a secret.
     */
    public static function cacheKey(string $reference): string
    {
        return 'demo-payment:'.$reference;
    }

    /**
     * Application key. An empty key makes every signature fail.
     */
    private function key(): string
    {
        $key = config('app.key');

        return is_string($key) ? $key : '';
    }
}
