<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentsUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\InterswitchGateway;
use App\Support\CurrentStudent;
use Illuminate\Contracts\View\View;

/**
 * Interswitch redirect checkout. The signed-in student must own the payment.
 *
 * The form posts to Interswitch. This action does not call the provider.
 */
class InterswitchCheckoutController extends Controller
{
    public function __construct(private readonly InterswitchGateway $interswitch) {}

    /**
     * The auto-submitting payment form for one pending payment.
     */
    public function show(string $reference): View
    {
        $payment = $this->owned($reference);

        try {
            $checkout = $this->interswitch->checkout($payment);
        } catch (PaymentsUnavailableException) {
            abort(404);
        }

        return view('student.interswitch-checkout', [
            'payment' => $payment,
            'action' => $checkout['action'],
            'fields' => $checkout['fields'],
        ]);
    }

    /**
     * This student's pending Interswitch payment. Missing and foreign are the same 404.
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

        abort_unless(
            $payment instanceof Payment
            && $payment->gateway === PaymentGateway::Interswitch
            && $payment->status === PaymentStatus::Pending,
            404,
        );

        return $payment;
    }
}
