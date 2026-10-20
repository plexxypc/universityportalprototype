<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EmailTemplate;
use App\Enums\NotificationType;
use App\Enums\PaymentEventSource;
use App\Enums\PaymentGateway as PaymentGatewayName;
use App\Enums\PaymentStatus;
use App\Models\Counter;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Receipt;
use App\Models\User;
use App\Services\Payments\GatewayOutcome;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Payments\StartedPayment;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Starts a payment against one invoice.
 *
 * initiate locks the invoice row only. Other pending amounts are read
 * without locking payment rows. Settlement locks the payment first.
 */
class PaymentService
{
    public const string ACTION_INITIATED = 'payment.initiated';

    public const string ACTION_SETTLED = 'payment.settled';

    public const string ACTION_REVERSED = 'payment.reversed';

    public const string ACTION_OVERPAYMENT = 'payment.overpayment_review';

    public const string ACTION_REVIEW_RESOLVED = 'payment.review_resolved';

    public const string MESSAGE_REVERSE = 'You cannot reverse a payment.';

    public const string MESSAGE_PAYMENT = 'That payment could not be found.';

    public const string MESSAGE_REVERSE_STATE = 'Only a successful payment can be reversed.';

    public const string REVIEW_TITLE = 'Payment under review';

    public const string MESSAGE_MAKE = 'You cannot make a payment.';

    public const string MESSAGE_MISSING = 'That invoice could not be found.';

    public const string MESSAGE_CLOSED = 'This invoice is cancelled.';

    public const string MESSAGE_AMOUNT = 'Enter an amount of at least ₦0.01.';

    public const string MESSAGE_BALANCE = 'That amount is more than the unpaid balance.';

    public const string MESSAGE_INSTALMENT = 'Pay at least the minimum instalment, or the remaining balance.';

    public const string MESSAGE_PENDING_LIMIT = 'This invoice already has the maximum number of payments in progress.';

    public const string MESSAGE_HOURLY = 'You have started too many payments. Try again later.';

    public function __construct(
        private readonly Audit $audit,
        private readonly PaymentGatewayResolver $gateways,
        private readonly MailService $mail,
        private readonly NotificationService $notifications,
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
            $this->assertStartLimits($invoice);
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
     * Cap open reservations on one invoice and starts by one student in an hour.
     *
     * The caller holds the invoice row. An expired Pending payment does not count.
     */
    private function assertStartLimits(Invoice $invoice): void
    {
        $pending_limit = max(1, (int) config('payments.max_pending_per_invoice'));
        $pending = Payment::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('status', PaymentStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->count();

        if ($pending >= $pending_limit) {
            throw ValidationException::withMessages(['payment' => self::MESSAGE_PENDING_LIMIT]);
        }

        $hourly_limit = max(1, (int) config('payments.max_initiations_per_hour'));
        $started = Payment::query()
            ->where('student_id', $invoice->student_id)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($started >= $hourly_limit) {
            throw ValidationException::withMessages(['payment' => self::MESSAGE_HOURLY]);
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

    /**
     * Ask the gateway about this reference and apply the result.
     *
     * An unknown reference makes no outbound call. A reference is asked
     * at most once per the configured interval. The payment row is locked
     * before the invoice row.
     */
    public function verifyAndSettle(string $reference, string $source): void
    {
        if (preg_match('/\A[A-Za-z0-9]{1,64}\z/', $reference) !== 1) {
            return;
        }

        $existing = Payment::query()->where('reference', $reference)->first();

        if (! $existing instanceof Payment || ! $this->claimVerify($reference)) {
            return;
        }

        $outcome = $this->gateways->resolve()->verify($reference);

        DB::transaction(function () use ($existing, $outcome, $source): void {
            $payment = Payment::query()->whereKey($existing->getKey())->lockForUpdate()->first();
            $invoice = $payment instanceof Payment
                ? Invoice::query()->whereKey($payment->invoice_id)->lockForUpdate()->first()
                : null;

            if (! $payment instanceof Payment || ! $invoice instanceof Invoice) {
                return;
            }

            $payment->last_checked_at = now();
            $this->applyOutcome($payment, $invoice, $outcome, $source);
            $payment->save();
        });
    }

    /**
     * Mark a successful payment reversed and subtract its amount once.
     *
     * The payment row is locked before the invoice row. The row is kept.
     */
    public function reverse(User $actor, int $payment_id, string $reason, Request $request): Payment
    {
        if (! Gate::forUser($actor)->allows('fees.manage')) {
            throw new AuthorizationException(self::MESSAGE_REVERSE);
        }

        $reason = $this->reason($reason);

        return DB::transaction(function () use ($actor, $payment_id, $reason, $request): Payment {
            if (! Gate::forUser($actor)->allows('fees.manage')) {
                throw new AuthorizationException(self::MESSAGE_REVERSE);
            }

            $payment = Payment::query()->visibleTo($actor)->whereKey($payment_id)->lockForUpdate()->first();

            if (! $payment instanceof Payment) {
                throw ValidationException::withMessages(['payment' => self::MESSAGE_PAYMENT]);
            }

            $invoice = Invoice::query()->whereKey($payment->invoice_id)->lockForUpdate()->first();

            if (! $invoice instanceof Invoice) {
                throw ValidationException::withMessages(['payment' => self::MESSAGE_PAYMENT]);
            }

            if ($payment->status !== PaymentStatus::Successful) {
                throw ValidationException::withMessages(['payment' => self::MESSAGE_REVERSE_STATE]);
            }

            $payment->status = PaymentStatus::Reversed;
            $payment->reversed_reason = $reason;
            $payment->reversed_by = (int) $actor->getKey();
            $payment->reversed_at = now();
            $payment->save();
            $invoice->paid_kobo = max(0, (int) $invoice->paid_kobo - (int) $payment->amount_kobo);
            $invoice->status = InvoiceService::statusFor(
                (int) $invoice->total_kobo,
                (int) $invoice->adjustments_kobo,
                (int) $invoice->paid_kobo,
            );
            $invoice->save();

            $this->audit->record(
                (int) $actor->getKey(),
                self::ACTION_REVERSED,
                'payments',
                (int) $payment->getKey(),
                ['status' => PaymentStatus::Successful->value, 'amount_kobo' => (int) $payment->amount_kobo],
                [
                    'payment_id' => (int) $payment->getKey(),
                    'invoice_id' => (int) $invoice->getKey(),
                    'status' => PaymentStatus::Reversed->value,
                    'paid_kobo' => (int) $invoice->paid_kobo,
                    'reason' => $reason,
                ],
                $this->audit->ipFromRequest($request),
            );

            return $payment;
        });
    }

    /**
     * Close an overpayment review. The event is insert-only.
     */
    public function resolveReview(User $actor, string $reference, string $reason, Request $request): void
    {
        if (! Gate::forUser($actor)->allows('fees.manage')) {
            throw new AuthorizationException(self::MESSAGE_REVERSE);
        }

        $reason = $this->reason($reason);

        DB::transaction(function () use ($actor, $reference, $reason, $request): void {
            $payment = Payment::query()->visibleTo($actor)->where('reference', $reference)->lockForUpdate()->first();

            if (! $payment instanceof Payment || ! $this->hasEvent($payment, 'overpayment_review:'.$reference)) {
                throw ValidationException::withMessages(['payment' => self::MESSAGE_PAYMENT]);
            }

            $this->recordEvent($payment, PaymentEventSource::Manual->value, 'review_resolved:'.$reference, [
                'reference' => $reference,
                'result' => 'resolved',
            ]);
            $this->audit->record(
                (int) $actor->getKey(),
                self::ACTION_REVIEW_RESOLVED,
                'payments',
                (int) $payment->getKey(),
                null,
                [
                    'payment_id' => (int) $payment->getKey(),
                    'reference' => $reference,
                    'reason' => $reason,
                ],
                $this->audit->ipFromRequest($request),
            );
        });
    }

    /**
     * Pending payments whose success could not be credited.
     */
    public function openReviewCount(): int
    {
        return Payment::query()
            ->where('status', PaymentStatus::Pending->value)
            ->whereRaw(
                "exists (select 1 from `payment_events` where `payment_events`.`payment_id` = `payments`.`id` and `payment_events`.`event_key` = concat('overpayment_review:', `payments`.`reference`))",
            )
            ->whereRaw(
                "not exists (select 1 from `payment_events` where `payment_events`.`payment_id` = `payments`.`id` and `payment_events`.`event_key` = concat('review_resolved:', `payments`.`reference`))",
            )
            ->count();
    }

    /**
     * Whether this payment is waiting for the bursary to review an overpayment.
     */
    public function underReview(Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Pending
            && $this->hasEvent($payment, 'overpayment_review:'.$payment->reference)
            && ! $this->hasEvent($payment, 'review_resolved:'.$payment->reference);
    }

    /**
     * Cache key that spaces out verification of one reference.
     */
    public static function verifyCacheKey(string $reference): string
    {
        return 'payment-verify:'.$reference;
    }

    /**
     * True when this call may ask the gateway. False when a recent check exists.
     */
    private function claimVerify(string $reference): bool
    {
        $key = self::verifyCacheKey($reference);

        if (Cache::has($key)) {
            return false;
        }

        $seconds = max(1, (int) config('payments.verify_interval_seconds'));
        Cache::put($key, 1, now()->addSeconds($seconds));

        return true;
    }

    /**
     * Apply one gateway result. A success that does not match is not credited.
     */
    private function applyOutcome(Payment $payment, Invoice $invoice, GatewayOutcome $outcome, string $source): void
    {
        if ($payment->status === PaymentStatus::Successful || $payment->status === PaymentStatus::Reversed) {
            return;
        }

        if ($outcome->result === 'success' && $this->refusesSuccess($payment, $outcome)) {
            $this->recordEvent($payment, $source, 'mismatch:'.$payment->reference, [
                'reference' => $payment->reference,
                'amount_kobo' => $outcome->amount_kobo,
                'currency' => $outcome->currency,
                'result' => 'mismatch',
            ]);

            return;
        }

        if ($outcome->result === 'success') {
            if ((int) $payment->amount_kobo > InvoiceService::outstandingKobo($invoice)) {
                $payment->status = PaymentStatus::Pending;

                if ($this->hasEvent($payment, 'overpayment_review:'.$payment->reference)) {
                    return;
                }

                $this->recordEvent($payment, $source, 'overpayment_review:'.$payment->reference, [
                    'reference' => $payment->reference,
                    'amount_kobo' => (int) $payment->amount_kobo,
                    'result' => 'overpayment_review',
                ]);
                $this->audit->record(
                    null,
                    self::ACTION_OVERPAYMENT,
                    'payments',
                    (int) $payment->getKey(),
                    null,
                    [
                        'payment_id' => (int) $payment->getKey(),
                        'invoice_id' => (int) $invoice->getKey(),
                        'amount_kobo' => (int) $payment->amount_kobo,
                        'status' => PaymentStatus::Pending->value,
                    ],
                    null,
                );

                return;
            }

            if ($payment->status === PaymentStatus::Pending || $payment->status === PaymentStatus::Expired) {
                $this->credit($payment, $invoice, $source);
            }

            return;
        }

        if ($payment->status !== PaymentStatus::Pending) {
            return;
        }

        if ($outcome->result === 'failed') {
            $payment->status = PaymentStatus::Failed;

            return;
        }

        if ($outcome->result === 'cancelled') {
            $payment->status = PaymentStatus::Cancelled;

            return;
        }

        if ($payment->expires_at->lte(now())) {
            $payment->status = PaymentStatus::Expired;
        }
    }

    /**
     * A success whose amount, currency, or reference does not match.
     *
     * A missing currency is not a refusal.
     */
    private function refusesSuccess(Payment $payment, GatewayOutcome $outcome): bool
    {
        if ($outcome->reference !== $payment->reference) {
            return true;
        }

        if ($outcome->amount_kobo !== (int) $payment->amount_kobo) {
            return true;
        }

        return $outcome->currency !== null && $outcome->currency !== '566';
    }

    /**
     * Credit one verified payment, once, with its receipt and notices.
     */
    private function credit(Payment $payment, Invoice $invoice, string $source): void
    {
        $payment->status = PaymentStatus::Successful;
        $payment->paid_at = now();
        $invoice->paid_kobo = (int) $invoice->paid_kobo + (int) $payment->amount_kobo;
        $invoice->status = InvoiceService::statusFor(
            (int) $invoice->total_kobo,
            (int) $invoice->adjustments_kobo,
            (int) $invoice->paid_kobo,
        );
        $invoice->save();
        $receipt = new Receipt;
        $receipt->payment_id = (int) $payment->getKey();
        $receipt->number = $this->nextReceiptNumber();
        $receipt->save();
        $this->recordEvent($payment, $source, 'settled:'.$payment->reference, [
            'reference' => $payment->reference,
            'amount_kobo' => (int) $payment->amount_kobo,
            'currency' => '566',
            'result' => 'success',
        ]);
        $this->audit->record(
            null,
            self::ACTION_SETTLED,
            'payments',
            (int) $payment->getKey(),
            null,
            [
                'payment_id' => (int) $payment->getKey(),
                'invoice_id' => (int) $invoice->getKey(),
                'receipt_number' => $receipt->number,
                'amount_kobo' => (int) $payment->amount_kobo,
                'paid_kobo' => (int) $invoice->paid_kobo,
                'status' => PaymentStatus::Successful->value,
            ],
            null,
        );
        $this->notifyPaid($payment, $receipt->number);
    }

    /**
     * In-app notice and one confirmation email. A mail problem does not undo the credit.
     */
    private function notifyPaid(Payment $payment, string $receipt_number): void
    {
        $payment->loadMissing('student.user');
        $user = $payment->student?->user;

        if (! $user instanceof User) {
            return;
        }

        try {
            $this->notifications->create($user, NotificationType::PaymentConfirmed->value, [
                'title' => 'Payment confirmed',
                'message' => 'Your payment was received. Receipt '.$receipt_number.'.',
                'link' => '/student/fees',
            ]);
        } catch (Throwable) {
            Log::warning('The payment confirmation notice was not saved.');
        }

        if ($user->email === '') {
            return;
        }

        try {
            $this->mail->send(
                EmailTemplate::PaymentConfirmed->value,
                $user->email,
                [
                    'name' => (string) $user->name,
                    'amount_kobo' => (int) $payment->amount_kobo,
                    'receipt_number' => $receipt_number,
                    'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
                ],
                (int) $user->getKey(),
                'payments',
                (int) $payment->getKey(),
            );
        } catch (Throwable) {
            Log::warning('The payment confirmation email was not queued.');
        }
    }

    /**
     * Insert one whitelisted event. A repeated key is ignored.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordEvent(Payment $payment, string $source, string $event_key, array $payload): void
    {
        $clean = [];

        foreach (['reference', 'amount_kobo', 'currency', 'result'] as $key) {
            if (array_key_exists($key, $payload)) {
                $clean[$key] = $payload[$key];
            }
        }

        try {
            $event = new PaymentEvent;
            $event->provider = $payment->gateway;
            $event->event_key = $event_key;
            $event->payload = $clean;
            $event->source = PaymentEventSource::from($source);
            $event->payment_id = (int) $payment->getKey();
            $event->save();
        } catch (QueryException) {
            return;
        }
    }

    /**
     * Whether this event key is already stored.
     */
    private function hasEvent(Payment $payment, string $event_key): bool
    {
        return PaymentEvent::query()
            ->where('payment_id', $payment->getKey())
            ->where('event_key', $event_key)
            ->exists();
    }

    /**
     * A required reason, trimmed, at most 500 characters.
     */
    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Enter a reason.']);
        }

        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'The reason must be 500 characters or fewer.']);
        }

        return $reason;
    }

    /**
     * Next receipt number from the counter for this Lagos year.
     *
     * The caller holds the transaction. The format is RCT-2026-000042.
     */
    private function nextReceiptNumber(): string
    {
        $year = now()->format('Y');
        $key = 'receipt:'.$year;
        $now = now();

        DB::table('counters')->insertOrIgnore([
            'key' => $key,
            'value' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $counter = Counter::query()->where('key', $key)->lockForUpdate()->first();

        if (! $counter instanceof Counter) {
            throw ValidationException::withMessages(['payment' => self::MESSAGE_PAYMENT]);
        }

        $next = (int) $counter->value + 1;
        $counter->value = $next;
        $counter->save();

        return 'RCT-'.$year.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
