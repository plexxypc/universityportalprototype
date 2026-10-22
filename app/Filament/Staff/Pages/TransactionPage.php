<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Filament\Staff\Pages\Concerns\RequiresFeesView;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\PaymentService;
use App\Support\Money;
use App\Support\StatusTone;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * One payment. Re-verify and reversal call PaymentService.
 *
 * Nothing on this page marks a payment Successful by hand.
 */
class TransactionPage extends Page
{
    use RequiresFeesView;

    protected static ?string $slug = 'transactions/{payment}';

    protected static bool $shouldRegisterNavigation = false;

    public int $payment_id = 0;

    public string $reversal_reason = '';

    public function mount(int $payment): void
    {
        $row = Payment::query()->visibleTo($this->actor())->whereKey($payment)->first();

        abort_if($row === null, 404);

        $this->payment_id = (int) $row->getKey();
    }

    public function getTitle(): string
    {
        return 'Transaction';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.staff.transaction'),
        ]);
    }

    /**
     * Check this payment with the provider. The button stays disabled while it runs.
     */
    public function reverifyPayment(): void
    {
        abort_unless(static::feesManage(), 403);

        try {
            $message = app(PaymentService::class)->reverify($this->actor(), $this->payment_id, request());
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->validator->errors()->first() ?: PaymentService::MESSAGE_PAYMENT)->danger()->send();

            return;
        } catch (AuthorizationException) {
            abort(403);
        }

        Notification::make()->title($message)->success()->send();
    }

    /**
     * Reverse a successful payment in the ledger. This does not refund the customer.
     */
    public function reversePayment(): void
    {
        abort_unless(static::feesManage(), 403);

        try {
            app(PaymentService::class)->reverse($this->actor(), $this->payment_id, $this->reversal_reason, request());
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->validator->errors()->first() ?: PaymentService::MESSAGE_REVERSE_STATE)->danger()->send();

            return;
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->reversal_reason = '';
        Notification::make()->title('Payment reversed')->success()->send();
    }

    /**
     * Fields and the event timeline for the view.
     *
     * @return array<string, mixed>
     */
    public function transactionView(): array
    {
        $payment = Payment::query()
            ->visibleTo($this->actor())
            ->with(['student.user', 'invoice', 'receipt', 'events'])
            ->whereKey($this->payment_id)
            ->first();

        abort_if($payment === null, 404);

        $events = [];

        foreach ($payment->events as $event) {
            $events[] = $this->eventLine($event);
        }

        $receipt = $payment->receipt;

        return [
            'reference' => $payment->reference,
            'student' => trim((string) $payment->student?->user?->name.' '.(string) $payment->student?->matric_no),
            'invoice_number' => (string) $payment->invoice?->number,
            'invoice_url' => InvoicesPage::getUrl(),
            'amount' => Money::format((int) $payment->amount_kobo),
            'status' => $payment->status->value,
            'tone' => StatusTone::classes(StatusTone::for($payment->status)),
            'gateway' => $payment->gateway->value,
            'provider_reference' => (string) $payment->provider_reference,
            'paid_at' => $payment->paid_at?->timezone('Africa/Lagos')->format('Y-m-d H:i') ?? '',
            'created_at' => $payment->created_at?->timezone('Africa/Lagos')->format('Y-m-d H:i') ?? '',
            'can_manage' => static::feesManage(),
            'can_reverse' => static::feesManage() && $payment->status->value === 'Successful',
            'reversal_notice' => PaymentService::REVERSAL_NOTICE,
            'receipt_url' => $receipt === null ? '' : route('staff.downloads.receipt', ['receipt' => $receipt->getKey()]),
            'receipt_pdf_url' => $receipt === null ? '' : route('staff.downloads.receipt.pdf', ['receipt' => $receipt->getKey()]),
            'events' => $events,
        ];
    }

    /**
     * Whitelisted event fields. The stored payload is not printed.
     *
     * @return array<string, string>
     */
    private function eventLine(PaymentEvent $event): array
    {
        $payload = $event->payload;
        $amount = $payload['amount_kobo'] ?? null;

        return [
            'source' => $event->source->value,
            'reference' => is_string($payload['reference'] ?? null) ? $payload['reference'] : '',
            'amount' => is_int($amount) ? Money::format($amount) : '',
            'currency' => is_string($payload['currency'] ?? null) ? $payload['currency'] : '',
            'result' => is_string($payload['result'] ?? null) ? $payload['result'] : '',
            'at' => $event->created_at?->timezone('Africa/Lagos')->format('Y-m-d H:i') ?? '',
        ];
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(404);
        }

        return $actor;
    }
}
