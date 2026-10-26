<?php

namespace App\Payments\Contracts;

use App\Models\PaymentTransaction;
use App\Payments\GatewayNotification;
use App\Payments\GatewayResult;
use Illuminate\Http\Request;

/**
 * A way of collecting money (Yo! Payments today; others later).
 *
 * Implementations only translate between this app and the provider's API.
 * They never change transaction records themselves — PaymentService does
 * that, so every gateway gets the same state handling and safeguards.
 */
interface PaymentGateway
{
    /** Short machine name stored on each transaction, e.g. "yo". */
    public function name(): string;

    /** Whether credentials etc. are present, so the gateway can be offered at all. */
    public function isConfigured(): bool;

    /**
     * Ask the payer to pay (e.g. trigger a mobile-money approval prompt).
     * Should return quickly with a PENDING result where the provider allows.
     */
    public function requestPayment(PaymentTransaction $transaction): GatewayResult;

    /** Authoritative status of an earlier request, straight from the provider. */
    public function checkStatus(PaymentTransaction $transaction): GatewayResult;

    /**
     * Read a provider callback (success / failure notification).
     * $type is the last URL segment, e.g. "ipn" or "failure".
     * Must verify authenticity where the provider supports it.
     */
    public function parseNotification(Request $request, string $type): GatewayNotification;

    /** Body to send back to the provider after handling a notification. */
    public function notificationResponse(GatewayNotification $notification): string;
}
