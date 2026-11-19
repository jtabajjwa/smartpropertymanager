<?php

namespace App\Services;

use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Payments\Msisdn;
use App\Payments\PaymentService;
use App\Support\PlanFeatures;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Paying for a Primora subscription by mobile money, shared by the web
 * portal and the mobile app's API: the plans and unit tiers a landlord can
 * choose, their promotion (partner / campaign code), and starting a payment.
 *
 * Problems with a field throw ValidationException; others CheckoutException.
 */
class SubscriptionCheckout
{
    public function __construct(protected SubscriptionBilling $billing, protected PaymentService $payments, protected PartnerProgram $program)
    {
    }

    /** Every plan with its unit tiers (tiers too small for the landlord's units are listed but not selectable). */
    public function plans(Landlord $landlord, ?int $units = null): Collection
    {
        $units ??= $this->billing->unitCount($landlord);

        return $this->billing->availablePlans()->map(fn (Plan $plan) => [
            'id' => $plan->id,
            'name' => $plan->name,
            'tiered' => $plan->isTiered(),
            'features' => collect($plan->featureKeys())->map(fn ($k) => PlanFeatures::name($k))->values(),
            'missing' => collect(PlanFeatures::FEATURES)->keys()->reject(fn ($k) => $plan->includes($k))->map(fn ($k) => PlanFeatures::name($k))->values(),
            'tiers' => $this->billing->tierOptions($plan, $units),
            'default_tier' => $this->billing->defaultTierId($plan, $units, $landlord->max_units),
        ])->filter(fn ($p) => collect($p['tiers'])->contains(fn ($t) => $t['eligible'] && $t['prices']))->values();
    }

    /** The landlord's running promotion, as the price screens need it; null if none applies any more. */
    public function promo(Landlord $landlord): ?array
    {
        $promotion = $landlord->promotion;
        if (!$promotion || !$promotion->isRunning($landlord) || $landlord->promotion_periods_used >= $promotion->periods) {
            return null;
        }

        return [
            'name' => $promotion->name,
            'summary' => $promotion->summary(),
            'type' => $promotion->discount_type,
            'value' => $promotion->discount_value,
            'plan_ids' => $promotion->plan_ids ? array_map('intval', $promotion->plan_ids) : null,
            'cycles' => $promotion->cycles,
            'left' => $promotion->periods - $landlord->promotion_periods_used,
        ];
    }

    /** Send the mobile money prompt for a plan, tier and cycle (with any promotion's discount). */
    public function start(Landlord $landlord, \Illuminate\Contracts\Auth\Authenticatable $by, int $planId, string $cycle, string $phone, ?int $tierId): PaymentTransaction
    {
        $msisdn = Msisdn::normalize($phone);
        if (!$msisdn) {
            throw ValidationException::withMessages(['phone' => 'Enter a Ugandan mobile money number, e.g. 0772 123 456.']);
        }

        // Don't send a second prompt while one is still waiting on the phone.
        if (PaymentTransaction::forLandlord($landlord->id)->where('purpose', 'subscription')->open()->where('created_at', '>', now()->subMinutes(3))->exists()) {
            throw new CheckoutException('A payment request is already waiting for approval on a phone. Approve or decline it first, or try again in a few minutes.');
        }

        $plan = Plan::with('tiers')->findOrFail($planId);
        $units = $this->billing->unitCount($landlord);
        $tierId ??= $this->billing->defaultTierId($plan, $units, $landlord->max_units);
        $quote = $this->billing->quoteForTier($plan, $tierId, $cycle, $units);

        if (!$quote) {
            $chosen = $plan->tiers->firstWhere('id', $tierId);
            throw new CheckoutException($chosen && $chosen->max_units !== null && $chosen->max_units < $units
                ? "That tier covers up to {$chosen->max_units} units, but you already have {$units}. Please choose a larger tier."
                : "That plan and tier can't be paid for {$units} units. Please choose another, or contact Primora.");
        }

        // A promotion (partner or campaign code) takes money off the first paid periods.
        $discount = $this->program->discountFor($landlord, $plan->id, $cycle, (float) $quote['amount']);
        $promoContext = $discount ? [
            'list_amount' => $discount['list_amount'],
            'discount' => $discount['discount'],
            'promotion_id' => $discount['promotion_id'],
            'promotion' => $discount['promotion'],
            'period_number' => $discount['period_number'],
            'partner_id' => $landlord->partner_id,
            'partner_share' => $discount['partner_share'],
            'primora_share' => $discount['primora_share'],
        ] : [];

        try {
            return $this->payments->initiate(
                purpose: 'subscription',
                landlord: $landlord,
                amount: $discount['amount'] ?? $quote['amount'],
                msisdn: $msisdn,
                context: [
                    'plan_id' => $plan->id,
                    'plan_name' => $plan->name,
                    'cycle' => $cycle,
                    'units' => $units,
                    'tier' => $quote['tier'],
                    'tier_id' => $quote['tier_id'],
                    'max_units' => $quote['max_units'],
                ] + $promoContext,
                payable: $landlord,
                initiator: $by,
            );
        } catch (\App\Payments\PaymentException $e) {
            throw new CheckoutException($e->getMessage());
        }
    }

    /** A partner or promo code, added once, before the first paid subscription. @return string what it gives */
    public function applyCode(Landlord $landlord, string $input): string
    {
        if (!$this->program->canTakeCode($landlord)) {
            throw new CheckoutException('A code can only be added once, before your first subscription payment.');
        }
        $code = $this->program->resolve($input);
        if (!$code) {
            throw ValidationException::withMessages(['code' => "We don't recognise that code. Check it with the person who gave it to you."]);
        }

        $this->program->attach($landlord, $code, 'payment');
        $promotion = $landlord->fresh()->promotion;

        return 'Code '.$code->code.' added.'.($promotion ? ' '.$promotion->summary().'.' : '');
    }
}
