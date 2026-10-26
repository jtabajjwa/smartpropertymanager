<?php

namespace App\Payments\Purposes;

use App\Models\PaymentTransaction;
use App\Models\StaffUser;
use App\Payments\Contracts\PaymentPurpose;
use App\Services\SmsCredits;

/**
 * A landlord buying SMS credits (a bundle, or a quantity staff record offline).
 *
 * Context saved on the transaction: bundle_id, bundle_name, quantity.
 */
class SmsCreditPurpose implements PaymentPurpose
{
    public function __construct(protected SmsCredits $credits)
    {
    }

    public function code(): string
    {
        return 'SMS';
    }

    public function describe(PaymentTransaction $transaction): string
    {
        $c = $transaction->context ?? [];
        $landlordName = $transaction->landlord?->name ?? 'Landlord #'.$transaction->landlord_id;
        $what = trim(($c['bundle_name'] ?? 'SMS credits').', '.number_format((int) ($c['quantity'] ?? 0)).' SMS', ', ');

        return "{$what} | {$landlordName}";
    }

    public function onSucceeded(PaymentTransaction $transaction): void
    {
        $quantity = (int) ($transaction->context['quantity'] ?? 0);

        if ($quantity > 0) {
            $this->credits->add(
                $transaction->landlord_id,
                $quantity,
                'purchase',
                "Payment {$transaction->reference}",
                payment: $transaction,
                staff: $transaction->initiated_by_staff_user_id ? StaffUser::find($transaction->initiated_by_staff_user_id) : null,
            );
        }
    }

    public function onFailed(PaymentTransaction $transaction): void
    {
        // Nothing to undo: credits are only added on success.
    }
}
