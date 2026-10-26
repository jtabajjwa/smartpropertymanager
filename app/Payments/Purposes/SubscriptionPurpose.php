<?php

namespace App\Payments\Purposes;

use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Payments\Contracts\PaymentPurpose;
use App\Services\SubscriptionBilling;

/**
 * A landlord paying for their Primora subscription.
 *
 * Context saved on the transaction when it starts:
 *   plan_id, plan_name, cycle, units, tier
 * Added when it succeeds:
 *   period_start, period_end
 */
class SubscriptionPurpose implements PaymentPurpose
{
    public function __construct(protected SubscriptionBilling $billing)
    {
    }

    public function code(): string
    {
        return 'SUB';
    }

    public function describe(PaymentTransaction $transaction): string
    {
        $c = $transaction->context ?? [];
        $landlordName = $transaction->landlord?->name ?? 'Landlord #'.$transaction->landlord_id;

        $plan = collect([$c['plan_name'] ?? null, $c['tier'] ?? null, SubscriptionBilling::cycleLabel($c['cycle'] ?? null)])
            ->filter()->implode(', ');

        return "{$plan} | {$landlordName}";
    }

    public function onSucceeded(PaymentTransaction $transaction): void
    {
        $context = $transaction->context ?? [];

        /** @var Landlord $landlord */
        $landlord = Landlord::query()->lockForUpdate()->findOrFail($transaction->landlord_id);

        $periodStart = $landlord->current_period_end && $landlord->current_period_end->isFuture()
            ? $landlord->current_period_end->copy()
            : today();
        $periodEnd = $this->billing->nextPeriodEnd($landlord, $context['cycle'] ?? 'monthly');

        $landlord->withAuditNote("Paid: {$transaction->reference} (".strtoupper($transaction->gateway).", {$transaction->currency} ".number_format((float) $transaction->amount).')')->update([
            'subscription_status' => 'active',
            'plan_id' => $context['plan_id'] ?? $landlord->plan_id,
            'billing_cycle' => $context['cycle'] ?? $landlord->billing_cycle,
            // The paid tier becomes the unit limit (null = unlimited). Older payments
            // recorded before tiers were chosen don't carry it: leave the limit alone.
            'max_units' => array_key_exists('max_units', $context) ? $context['max_units'] : $landlord->max_units,
            'current_period_end' => $periodEnd->toDateString(),
            'grace_ends_at' => null,
        ]);

        $transaction->update(['context' => $context + [
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
        ]]);
    }

    public function onFailed(PaymentTransaction $transaction): void
    {
        // Nothing to undo: the subscription only changes on success.
    }
}
