<?php

namespace App\Http\Controllers;

use App\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives payment gateway callbacks, e.g. Yo! Payments' Instant Payment
 * Notification (IPN) and failure notification:
 *
 *   POST /payments/callback/yo/ipn
 *   POST /payments/callback/yo/failure
 *
 * Always answers 200 once the request has been read, so the gateway stops
 * retrying. Nothing in the callback is trusted directly: PaymentService
 * re-checks the payment's status with the gateway before acting.
 */
class PaymentCallbackController extends Controller
{
    public function __invoke(Request $request, string $gateway, string $type, PaymentService $payments)
    {
        if (!array_key_exists($gateway, config('payments.gateways', []))) {
            abort(404);
        }

        try {
            $notification = $payments->handleNotification($gateway, $type, $request);
        } catch (\InvalidArgumentException) {
            abort(404); // unknown notification type
        } catch (\Throwable $e) {
            // Let the gateway retry later (non-200), and keep a record.
            Log::error("Payment callback {$gateway}/{$type} failed: {$e->getMessage()}");
            report($e);

            return response('Temporarily unavailable', 503);
        }

        return response(app(\App\Payments\PaymentGatewayManager::class)->gateway($gateway)->notificationResponse($notification), 200)
            ->header('Content-Type', 'text/plain');
    }
}
