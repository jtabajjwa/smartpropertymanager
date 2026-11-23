<?php

namespace App\Payments\Purposes;

use App\Models\PaymentTransaction;
use App\Services\WhatsAppCredits;

/**
 * A landlord buying WhatsApp alert credit (a bundle, or a quantity staff record offline).
 *
 * Context saved on the transaction: bundle_id, bundle_name, quantity.
 */
class WhatsAppCreditPurpose extends SmsCreditPurpose
{
    public function __construct(WhatsAppCredits $credits)
    {
        parent::__construct($credits);
    }

    public function code(): string
    {
        return 'WA';
    }

    public function describe(PaymentTransaction $transaction): string
    {
        $c = $transaction->context ?? [];
        $landlordName = $transaction->landlord?->name ?? 'Landlord #'.$transaction->landlord_id;
        $what = trim(($c['bundle_name'] ?? 'WhatsApp credits').', '.number_format((int) ($c['quantity'] ?? 0)).' WhatsApp alerts', ', ');

        return "{$what} | {$landlordName}";
    }
}
