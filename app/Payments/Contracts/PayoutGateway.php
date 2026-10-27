<?php

namespace App\Payments\Contracts;

use App\Models\Liquidation;
use App\Models\PayoutAccount;
use App\Payments\GatewayResult;

/**
 * A gateway that can send money out to a mobile money number (landlord
 * payouts). Implemented alongside PaymentGateway by gateways that support it.
 */
interface PayoutGateway
{
    /** Ready to send money (credentials and, where required, a signing key). */
    public function canSendPayouts(): bool;

    /**
     * Send $liquidation->net_amount to its destination (a mobile money
     * number, or a bank account registered with the gateway) under
     * $liquidation->external_reference.
     *
     * Must never be retried automatically: if the result isn't definite,
     * return PENDING / INDETERMINATE and let checkPayout() find out.
     */
    public function sendPayout(Liquidation $liquidation): GatewayResult;

    /** Authoritative status of the payout's current attempt. */
    public function checkPayout(Liquidation $liquidation): GatewayResult;

    /**
     * Register a (staff-verified) bank account with the gateway so it can be
     * paid; returns the gateway's identifier for it.
     *
     * @param  array{name: string, address1: string, address2: string, city: string, state?: ?string, country: string, swift?: ?string}  $bank
     *
     * @throws \RuntimeException with a message safe to show staff
     */
    public function registerBankAccount(PayoutAccount $account, array $bank): string;
}
