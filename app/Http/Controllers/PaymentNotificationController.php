<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PaymentEventSource;
use App\Exceptions\PaymentsUnavailableException;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Provider notification. No session and no CSRF token.
 *
 * Known and unknown references get the same empty response. The body
 * can name a reference. It cannot credit a payment.
 */
class PaymentNotificationController extends Controller
{
    /**
     * Accept one notification and verify only the reference it names.
     */
    public function __invoke(Request $request, string $provider, PaymentService $payments): Response
    {
        $body = $request->getContent();

        if (strlen($body) > (int) config('payments.notification_max_bytes')) {
            return response('', 200);
        }

        if ($provider !== 'demo') {
            return response('', 200);
        }

        try {
            $reference = app(PaymentGatewayResolver::class)->resolve()->parseNotification($body);
        } catch (PaymentsUnavailableException) {
            return response('', 200);
        }

        if ($reference !== null) {
            $payments->verifyAndSettle($reference, PaymentEventSource::Notification->value);
        }

        return response('', 200);
    }
}
