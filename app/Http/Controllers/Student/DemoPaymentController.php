<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Exceptions\PaymentsUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\DemoGateway;
use App\Services\Payments\PaymentGatewayResolver;
use App\Support\CurrentStudent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Demo checkout. The signed-in student must own the payment.
 *
 * The outcome is stored only when its signature matches. Settlement
 * reads that store later and does not trust this request body.
 */
class DemoPaymentController extends Controller
{
    public const string MESSAGE_REJECTED = 'That payment could not be confirmed.';

    public function __construct(
        private readonly PaymentGatewayResolver $gateways,
        private readonly DemoGateway $demo,
    ) {}

    /**
     * Show the demo checkout for one of this student's payments.
     */
    public function show(string $reference): View
    {
        $this->usable();
        $payment = $this->owned($reference);
        $amount_kobo = (int) $payment->amount_kobo;
        $signatures = [];

        foreach (DemoGateway::OUTCOMES as $outcome) {
            $signatures[$outcome] = $this->demo->signature($reference, $amount_kobo, $outcome);
        }

        return view('student.demo-payment', [
            'payment' => $payment,
            'signatures' => $signatures,
            'banner' => DemoGateway::BANNER,
        ]);
    }

    /**
     * Store one signed outcome. A forged signature is refused.
     */
    public function store(Request $request, string $reference): RedirectResponse
    {
        $this->usable();
        $payment = $this->owned($reference);
        $outcome = $request->string('outcome')->toString();
        $signature = $request->string('signature')->toString();

        if (! in_array($outcome, DemoGateway::OUTCOMES, true)
            || ! $this->demo->signatureMatches($reference, (int) $payment->amount_kobo, $outcome, $signature)) {
            throw ValidationException::withMessages(['outcome' => self::MESSAGE_REJECTED]);
        }

        $this->demo->remember($reference, (int) $payment->amount_kobo, $outcome);

        return redirect()->route('payments.return', ['reference' => $reference], 303);
    }

    /**
     * Fail closed before the page renders a checkout.
     */
    private function usable(): void
    {
        try {
            $this->gateways->resolve();
        } catch (PaymentsUnavailableException $exception) {
            throw ValidationException::withMessages(['payment' => $exception->getMessage()]);
        }
    }

    /**
     * This student's payment. A missing reference and another student's are the same 404.
     */
    private function owned(string $reference): Payment
    {
        $student = CurrentStudent::require();
        $user = $student->user;

        abort_unless($user instanceof User, 404);

        $payment = Payment::query()
            ->visibleTo($user)
            ->where('reference', $reference)
            ->first();

        abort_unless($payment instanceof Payment, 404);

        return $payment;
    }
}
