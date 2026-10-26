<?php

namespace App\Payments\Contracts;

use App\Models\PaymentTransaction;

/**
 * What a payment is for (a subscription today; tenant rent later), and what
 * happens once it succeeds. Registered in config/payments.php → purposes.
 */
interface PaymentPurpose
{
    /** Short code used in references and narratives, e.g. "SUB". Keep it 3 letters. */
    public function code(): string;

    /** Human-readable details appended to the narrative (plan, period, payer…). */
    public function describe(PaymentTransaction $transaction): string;

    /**
     * Called exactly once when the payment succeeds, inside a DB transaction
     * with the payment row locked.
     */
    public function onSucceeded(PaymentTransaction $transaction): void;

    /** Called once when the payment fails for good. */
    public function onFailed(PaymentTransaction $transaction): void;
}
