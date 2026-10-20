<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * One payment provider.
 *
 * initialize starts a checkout. verify asks the provider about our
 * reference. parseNotification returns that reference, or null, and
 * never decides that money was received.
 */
interface PaymentGateway
{
    /**
     * Checkout URL for this pending payment.
     */
    public function initialize(Payment $payment): string;

    /**
     * Ask the provider about this reference.
     */
    public function verify(string $reference): GatewayOutcome;

    /**
     * Read our reference from a notification body.
     */
    public function parseNotification(string $body): ?string;
}
