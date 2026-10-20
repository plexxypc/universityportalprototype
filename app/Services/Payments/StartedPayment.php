<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * A pending payment and the checkout URL the student should open.
 */
final class StartedPayment
{
    public function __construct(
        public Payment $payment,
        public string $checkout_url,
    ) {}
}
