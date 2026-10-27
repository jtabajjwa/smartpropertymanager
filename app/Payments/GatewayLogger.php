<?php

namespace App\Payments;

use App\Models\PaymentGatewayLog;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Records exchanges with payment gateways (payment_gateway_logs). Gateways
 * mask their own secrets before calling this. Never lets a logging problem
 * break a payment.
 */
class GatewayLogger
{
    public function record(
        ?PaymentTransaction $transaction,
        string $gateway,
        string $action,
        string $direction,
        ?string $url = null,
        ?string $requestBody = null,
        ?int $responseStatus = null,
        ?string $responseBody = null,
        ?string $error = null,
        ?int $durationMs = null,
        ?string $note = null,
        ?\App\Models\Liquidation $liquidation = null,
    ): ?PaymentGatewayLog {
        try {
            return PaymentGatewayLog::create([
                'payment_transaction_id' => $transaction?->exists ? $transaction->id : null,
                'liquidation_id' => $liquidation?->exists ? $liquidation->id : null,
                'gateway' => $gateway,
                'action' => $action,
                'direction' => $direction,
                'url' => $url ? mb_substr($url, 0, 500) : null,
                'request_body' => $requestBody !== null ? mb_substr($requestBody, 0, 65000) : null,
                'response_status' => $responseStatus,
                'response_body' => $responseBody !== null ? mb_substr($responseBody, 0, 65000) : null,
                'error' => $error !== null ? mb_substr($error, 0, 2000) : null,
                'duration_ms' => $durationMs,
                'note' => $note !== null ? mb_substr($note, 0, 500) : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not write payment gateway log: '.$e->getMessage());

            return null;
        }
    }
}
