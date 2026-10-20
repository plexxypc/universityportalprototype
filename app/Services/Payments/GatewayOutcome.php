<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * What a gateway reported for one of our references.
 *
 * currency is null when the provider omitted it. amount_kobo is the
 * provider amount in minor units. result is success, failed, cancelled,
 * or pending.
 */
final class GatewayOutcome
{
    public function __construct(
        public string $reference,
        public int $amount_kobo,
        public ?string $currency,
        public string $result,
        public ?string $provider_reference,
    ) {}
}
