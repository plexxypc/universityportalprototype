<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Exceptions\PaymentsUnavailableException;
use Illuminate\Support\Facades\Log;

/**
 * The payment adapter for this process.
 *
 * A bad provider does not stop boot (ADR-032 point 7). It fails when a
 * payment is started. Boot writes one warning and no values.
 */
final class PaymentGatewayResolver
{
    private static bool $warned = false;

    /**
     * Allow another warning. Tests use this between cases.
     */
    public static function forgetWarning(): void
    {
        self::$warned = false;
    }

    /**
     * The adapter, or a domain exception when payments cannot be used.
     */
    public function resolve(): PaymentGateway
    {
        if ($this->problem() !== null) {
            $this->warnIfUnusable();

            throw new PaymentsUnavailableException;
        }

        if ($this->provider() === 'interswitch') {
            return app(InterswitchGateway::class);
        }

        return app(DemoGateway::class);
    }

    /**
     * One warning when this process cannot take a payment. No values are logged.
     */
    public function warnIfUnusable(): void
    {
        if ($this->problem() === null || self::$warned) {
            return;
        }

        self::$warned = true;
        Log::warning('Payments are not configured.');
    }

    /**
     * Whether /health should report the payment adapter as ready.
     */
    public function isConfigured(): bool
    {
        return $this->problem() === null;
    }

    /**
     * Why payments cannot be used, or null when an adapter may run.
     *
     * The returned word is not logged.
     */
    public function problem(): ?string
    {
        $provider = $this->provider();

        if ($provider === 'demo') {
            if (app()->environment('production') && config('payments.allow_demo') !== true) {
                return 'demo';
            }

            return null;
        }

        if ($provider === 'interswitch') {
            return $this->interswitchReady() ? null : 'interswitch';
        }

        return 'provider';
    }

    /**
     * Merchant code, pay item id, and TEST or LIVE. The client id and the
     * client secret are not required: the documented form and requery do
     * not send them.
     */
    public function interswitchReady(): bool
    {
        $merchant_code = config('payments.interswitch.merchant_code');
        $pay_item_id = config('payments.interswitch.pay_item_id');
        $mode = strtoupper(trim((string) config('payments.interswitch.mode')));

        return is_string($merchant_code) && trim($merchant_code) !== ''
            && is_string($pay_item_id) && trim($pay_item_id) !== ''
            && in_array($mode, ['TEST', 'LIVE'], true);
    }

    /**
     * The selected provider name, in lower case.
     */
    private function provider(): string
    {
        return strtolower(trim((string) config('payments.provider')));
    }
}
