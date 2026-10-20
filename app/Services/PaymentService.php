<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentGateway as PaymentGatewayName;
use App\Enums\PaymentStatus;
use App\Models\Counter;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Payments\StartedPayment;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Starts a payment against one invoice.
 *
 * initiate locks the invoice row only. Other pending amounts are read
 * without locking payment rows. Settlement locks the payment first.
 */
class PaymentService
{
    public const string ACTION_INITIATED = 'payment.initiated';

    public const string MESSAGE_MAKE = 'You cannot make a payment.';

    public const string MESSAGE_MISSING = 'That invoice could not be found.';

    public const string MESSAGE_CLOSED = 'This invoice is cancelled.';

    public const string MESSAGE_AMOUNT = 'Enter an amount of at least ₦0.01.';

    public const string MESSAGE_BALANCE = 'That amount is more than the unpaid balance.';

    public const string MESSAGE_INSTALMENT = 'Pay at least the minimum instalment, or the remaining balance.';

    public function __construct(
        private readonly Audit $audit,
        private readonly PaymentGatewayResolver $gateways,
    ) {}

    /**
     * Reserve a pending payment and return its checkout URL.
     *
     * The amount is naira text. It cannot exceed the outstanding balance
     * minus unexpired Pending payments. A partial amount must reach the
     * minimum instalment unless it is the whole remaining balance.
     */
    public function initiate(User $actor, int $invoice_id, string $amount, Request $request): StartedPayment
    {
        $this->authoriseMake($actor);
        $amount_kobo = $this->kobo($amount);

        return DB::transaction(function () use ($actor, $invoice_id, $amount_kobo, $request): StartedPayment {
            $this->authoriseMake($actor);
            $invoice = Invoice::query()->visibleTo($actor)->whereKey($invoice_id)->lockForUpdate()->first();

            if (! $invoice instanceof Invoice) {
                throw ValidationException::withMessages(['invoice' => self::MESSAGE_MISSING]);
            }

            if ($invoice->status->value === 'Cancelled') {
                throw ValidationException::withMessages(['invoice' => self::MESSAGE_CLOSED]);
            }

            $available = InvoiceService::outstandingKobo($invoice) - InvoiceService::reservedKobo($invoice);
            $this->assertAmount($amount_kobo, $available);
            $gateway = $this->gateways->resolve();
            $reference = $this->nextReference();
            $payment = new Payment;
            $payment->reference = $reference;
            $payment->gateway = PaymentGatewayName::Demo;
            $payment->provider_reference = 'demo-'.$reference;
            $payment->invoice_id = (int) $invoice->getKey();
            $payment->student_id = (int) $invoice->student_id;
            $payment->amount_kobo = $amount_kobo;
            $payment->status = PaymentStatus::Pending;
            $payment->expires_at = now()->addMinutes((int) config('payments.pending_minutes'));
            $payment->save();

            $this->audit->record(
                (int) $actor->getKey(),
                self::ACTION_INITIATED,
                'payments',
                (int) $payment->getKey(),
                null,
                [
                    'payment_id' => (int) $payment->getKey(),
                    'invoice_id' => (int) $invoice->getKey(),
                    'reference' => $reference,
                    'amount_kobo' => $amount_kobo,
                    'status' => PaymentStatus::Pending->value,
                ],
                $this->audit->ipFromRequest($request),
            );

            return new StartedPayment($payment, $gateway->initialize($payment));
        });
    }

    /**
     * The student may pay. Staff, including a Super Admin, may not.
     */
    private function authoriseMake(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('payments.make')) {
            throw new AuthorizationException(self::MESSAGE_MAKE);
        }
    }

    /**
     * Naira text as kobo. Zero and unreadable amounts are refused.
     */
    private function kobo(string $amount): int
    {
        try {
            $amount_kobo = Money::parseNaira($amount);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => self::MESSAGE_AMOUNT]);
        }

        if ($amount_kobo < 1) {
            throw ValidationException::withMessages(['amount' => self::MESSAGE_AMOUNT]);
        }

        return $amount_kobo;
    }

    /**
     * The amount fits the available balance and the instalment rule.
     */
    private function assertAmount(int $amount_kobo, int $available): void
    {
        if ($available < 1 || $amount_kobo > $available) {
            throw ValidationException::withMessages(['amount' => self::MESSAGE_BALANCE]);
        }

        $minimum = max(1, (int) config('payments.minimum_instalment_kobo'));

        if ($amount_kobo < $minimum && $amount_kobo !== $available) {
            throw ValidationException::withMessages(['amount' => self::MESSAGE_INSTALMENT]);
        }
    }

    /**
     * Next payment reference from the counter for this Lagos year.
     *
     * The caller holds the transaction. The format is P20260000000042.
     */
    private function nextReference(): string
    {
        $year = now()->format('Y');
        $key = 'payment:'.$year;
        $now = now();

        DB::table('counters')->insertOrIgnore([
            'key' => $key,
            'value' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $counter = Counter::query()->where('key', $key)->lockForUpdate()->first();

        if (! $counter instanceof Counter) {
            throw ValidationException::withMessages(['payment' => self::MESSAGE_MISSING]);
        }

        $next = (int) $counter->value + 1;
        $counter->value = $next;
        $counter->save();

        return 'P'.$year.str_pad((string) $next, 10, '0', STR_PAD_LEFT);
    }
}
