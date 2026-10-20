<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Enums\PaymentEventSource;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentsUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use App\Support\CurrentStudent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * One payment for the signed-in student.
 *
 * A missing reference and another student's payment are the same 404.
 */
class PaymentStatusController extends Controller
{
    public const string MESSAGE_REVIEW = 'This payment was received and is under review by the bursary.';

    public const string MESSAGE_REFUNDS = 'Refunds are handled outside this system.';

    /**
     * Show the stored status. The gateway is not called on this read.
     */
    public function show(string $reference, PaymentService $payments): View
    {
        $payment = $this->owned($reference);
        $payment->load('receipt');

        return view('student.payment-status', [
            'payment' => $payment,
            'under_review' => $payments->underReview($payment),
            'review_message' => self::MESSAGE_REVIEW,
            'refunds_message' => self::MESSAGE_REFUNDS,
            'summary' => $this->summary($payment),
        ]);
    }

    /**
     * Ask the gateway once, then return to this page.
     */
    public function check(string $reference, PaymentService $payments): RedirectResponse
    {
        $payment = $this->owned($reference);

        try {
            $payments->verifyAndSettle($payment->reference, PaymentEventSource::Manual->value);
        } catch (PaymentsUnavailableException $exception) {
            throw ValidationException::withMessages(['payment' => $exception->getMessage()]);
        }

        return redirect()
            ->route('student.payments.show', ['reference' => $payment->reference]);
    }

    /**
     * A short sentence for a status that is not under review.
     */
    private function summary(Payment $payment): string
    {
        return match ($payment->status) {
            PaymentStatus::Successful => 'This payment was received.',
            PaymentStatus::Failed => 'This payment did not go through.',
            PaymentStatus::Cancelled => 'This payment was cancelled.',
            PaymentStatus::Expired => 'This payment expired.',
            PaymentStatus::Reversed => 'This payment was reversed.',
            PaymentStatus::Pending => 'We are still confirming this payment.',
        };
    }

    /**
     * This student's payment.
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
