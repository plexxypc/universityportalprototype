<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Exceptions\PaymentsUnavailableException;
use Illuminate\Support\Facades\Log;

/**
 * The payment adapter for this process.
 *
 * A bad provider, a missing Interswitch value, or the Demo Gateway in
 * production without permission fails here. Boot only writes one warning.
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
     * Why payments cannot be used, or null when the demo adapter may run.
     *
     * Interswitch has no adapter in this phase, so a missing value and a
     * complete value both stay closed. The returned word is not logged.
     */
    public function problem(): ?string
    {
        $provider = strtolower(trim((string) config('payments.provider')));

        if ($provider === 'demo') {
            if (app()->environment('production') && config('payments.allow_demo') !== true) {
                return 'demo';
            }

            return null;
        }

        if ($provider === 'interswitch') {
            return 'interswitch';
        }

        return 'provider';
    }
}
