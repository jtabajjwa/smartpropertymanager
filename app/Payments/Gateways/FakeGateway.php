<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;
use App\Models\Liquidation;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Contracts\PayoutGateway;
use App\Payments\GatewayNotification;
use App\Payments\GatewayResult;
use Illuminate\Http\Request;

/**
 * Local development only: pretends the payer approved the prompt a few
 * seconds after it was sent. Amounts ending in 44 fail, like Yo!'s sandbox
 * (2944), so the failure screens can be tried too.
 *
 * Refuses to run in production.
 */
class FakeGateway implements PaymentGateway, PayoutGateway
{
    public function __construct(protected array $config)
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return !app()->environment('production');
    }

    public function requestPayment(PaymentTransaction $transaction): GatewayResult
    {
        $this->guard();
        $this->log($transaction, 'request-payment', "Simulated request to collect UGX {$transaction->amount} from {$transaction->msisdn}", 'Simulated: prompt sent, awaiting approval');

        return GatewayResult::pending('Simulated approval prompt sent.', gatewayReference: 'FAKE-'.$transaction->reference);
    }

    /** Simulated exchanges are logged too, so the staff payment log can be tried out locally. */
    protected function log(PaymentTransaction $transaction, string $action, string $request, string $response): void
    {
        app(\App\Payments\GatewayLogger::class)->record(
            $transaction, $this->name(), $action, \App\Models\PaymentGatewayLog::OUTBOUND,
            requestBody: $request, responseStatus: 200, responseBody: $response, durationMs: 0, note: 'Simulated (fake gateway)',
        );
    }

    public function checkStatus(PaymentTransaction $transaction): GatewayResult
    {
        $this->guard();

        if ($transaction->created_at->diffInSeconds(now()) < ($this->config['succeed_after_seconds'] ?? 8)) {
            $this->log($transaction, 'check-status', "Simulated status check for {$transaction->reference}", 'Simulated: still pending');

            return GatewayResult::pending('Waiting for the payer to approve (simulated).');
        }

        if (str_ends_with((string) (int) $transaction->amount, '44')) {
            $this->log($transaction, 'check-status', "Simulated status check for {$transaction->reference}", 'Simulated: FAILED (payer declined)');

            return GatewayResult::failed('Simulated failure: the payer declined.');
        }

        $this->log($transaction, 'check-status', "Simulated status check for {$transaction->reference}", 'Simulated: SUCCEEDED');

        return new GatewayResult(
            PaymentTransaction::SUCCEEDED,
            gatewayReference: 'FAKE-'.$transaction->reference,
            networkReference: 'SIM'.random_int(10000000, 99999999),
            receiptNumber: (string) random_int(1000000, 9999999),
            message: 'Simulated payment approved.',
            amount: (float) $transaction->amount,
        );
    }

    public function parseNotification(Request $request, string $type): GatewayNotification
    {
        return new GatewayNotification('success', $request->input('reference'), null, verified: false);
    }

    public function notificationResponse(GatewayNotification $notification): string
    {
        return 'OK';
    }

    protected function guard(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('The fake payment gateway cannot be used in production.');
        }
    }

    // --- Payouts ----------------------------------------------------------

    public function canSendPayouts(): bool
    {
        return $this->isConfigured();
    }

    public function sendPayout(Liquidation $liquidation): GatewayResult
    {
        $this->guard();
        $this->logPayout($liquidation, 'withdraw-funds', "Simulated withdrawal of UGX {$liquidation->net_amount} to {$liquidation->destinationSummary()}", 'Simulated: accepted, pending');

        return GatewayResult::pending('Simulated withdrawal accepted.', gatewayReference: 'FAKE-'.$liquidation->external_reference);
    }

    public function checkPayout(Liquidation $liquidation): GatewayResult
    {
        $this->guard();

        if (($liquidation->sent_at ?? now())->diffInSeconds(now()) < ($this->config['succeed_after_seconds'] ?? 8)) {
            $this->logPayout($liquidation, 'check-status', "Simulated status check for {$liquidation->external_reference}", 'Simulated: still pending');

            return GatewayResult::pending('Withdrawal in progress (simulated).');
        }

        if (str_ends_with((string) (int) $liquidation->net_amount, '44')) {
            $this->logPayout($liquidation, 'check-status', "Simulated status check for {$liquidation->external_reference}", 'Simulated: FAILED');

            return GatewayResult::failed('Simulated failure: the receiving number could not be credited.');
        }

        $this->logPayout($liquidation, 'check-status', "Simulated status check for {$liquidation->external_reference}", 'Simulated: SUCCEEDED');

        return new GatewayResult(
            \App\Models\PaymentTransaction::SUCCEEDED,
            gatewayReference: 'FAKE-'.$liquidation->external_reference,
            networkReference: 'SIM'.random_int(10000000, 99999999),
            message: 'Simulated withdrawal completed.',
            amount: (float) $liquidation->net_amount,
        );
    }

    public function registerBankAccount(\App\Models\PayoutAccount $account, array $bank): string
    {
        $this->guard();

        return 'FAKE-BANK-'.$account->id;
    }

    protected function logPayout(Liquidation $liquidation, string $action, string $request, string $response): void
    {
        app(\App\Payments\GatewayLogger::class)->record(
            null, $this->name(), $action, \App\Models\PaymentGatewayLog::OUTBOUND,
            requestBody: $request, responseStatus: 200, responseBody: $response, durationMs: 0, note: 'Simulated (fake gateway)', liquidation: $liquidation,
        );
    }
}
