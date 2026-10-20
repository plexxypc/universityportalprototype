<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PaymentEventSource;
use App\Exceptions\PaymentsUnavailableException;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Provider return. No session and no CSRF token.
 *
 * The handler reads the reference and asks the gateway. Amount, status,
 * and every other field are ignored. The browser is sent to the status page.
 */
class PaymentReturnController extends Controller
{
    /**
     * Verify the reference and redirect to the signed-in status page.
     */
    public function __invoke(Request $request, PaymentService $payments): RedirectResponse
    {
        $reference = $this->reference($request);

        if ($reference !== null) {
            try {
                $payments->verifyAndSettle($reference, PaymentEventSource::Callback->value);
            } catch (PaymentsUnavailableException) {
                // The status page explains that payments are unavailable.
            }
        }

        $target = $reference === null
            ? route('student.fees')
            : route('student.payments.show', ['reference' => $reference]);

        return redirect()->to($target, 303);
    }

    /**
     * The reference from the query or the body, and nothing else.
     */
    private function reference(Request $request): ?string
    {
        $query = $request->query('reference');
        $body = $request->request->get('reference');
        $value = is_string($query) && $query !== '' ? $query : $body;

        if (! is_string($value) || preg_match('/\A[A-Za-z0-9]{1,64}\z/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
