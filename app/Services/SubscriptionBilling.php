<?php

namespace App\Services;

use App\Models\Landlord;
use App\Models\Plan;
use App\Models\PlanTier;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Prices and periods for landlords' Primora subscriptions.
 */
class SubscriptionBilling
{
    /** Billing cycles a landlord can pay for: label and length in months. */
    public const CYCLES = [
        'monthly' => ['label' => 'Monthly', 'months' => 1],
        'quarterly' => ['label' => 'Quarterly', 'months' => 3],
        'annual' => ['label' => 'Annual', 'months' => 12],
    ];

    /** Stored subscription statuses staff can set. */
    public const STATUSES = [
        'trialing' => 'Free trial',
        'active' => 'Active',
        'past_due' => 'Past due',
        'cancelled' => 'Cancelled',
    ];

    /** Display label + badge colour for Landlord::subscriptionState(). */
    public const STATES = [
        'trial' => ['Free trial', 'blue'],
        'trial_ended' => ['Trial ended', 'red'],
        'active' => ['Active', 'green'],
        'expiring' => ['Expiring soon', 'orange'],
        'expired' => ['Expired', 'red'],
        'past_due' => ['Past due', 'red'],
        'cancelled' => ['Cancelled', 'gray'],
    ];

    /**
     * What the landlord's current plan + cycle is worth per month at their
     * size (for monthly recurring revenue), or null if it can't be priced.
     */
    public function monthlyValue(Landlord $landlord, int $units): ?float
    {
        if (!$landlord->plan || !isset(self::CYCLES[$landlord->billing_cycle])) {
            return null;
        }

        // Priced at the tier they pay for (their unit limit), not just what they use.
        $quote = $this->quote($landlord->plan, $landlord->billing_cycle, max($units, (int) $landlord->max_units));

        return $quote ? $quote['amount'] / self::CYCLES[$landlord->billing_cycle]['months'] : null;
    }

    /**
     * The unit tiers a landlord can choose for a plan. A tier is eligible if
     * it covers at least the units they already have. A flat-price plan has
     * one open-ended option.
     *
     * @return list<array{id: ?int, label: string, min_units: int, max_units: ?int, eligible: bool, prices: array<string, float>}>
     */
    public function tierOptions(Plan $plan, int $units): array
    {
        $prices = fn (callable $price) => collect(self::CYCLES)
            ->map(fn ($c, $cycle) => (float) $price($cycle))
            ->filter(fn ($amount) => $amount > 0)
            ->all();

        if (!$plan->isTiered()) {
            return [[
                'id' => null,
                'label' => 'Any number of units',
                'min_units' => 1,
                'max_units' => null,
                'eligible' => true,
                'prices' => $prices(fn ($cycle) => $plan->priceFor($cycle)),
            ]];
        }

        return $plan->tiers->map(fn (PlanTier $tier) => [
            'id' => $tier->id,
            'label' => self::tierLabel($tier),
            'min_units' => $tier->min_units,
            'max_units' => $tier->max_units,
            'eligible' => $tier->max_units === null || $tier->max_units >= $units,
            'prices' => $prices(fn ($cycle) => $tier->priceFor($cycle)),
        ])->values()->all();
    }

    /**
     * Tier to pre-select: the landlord's current paid tier if it still
     * covers them, otherwise the smallest tier that does.
     */
    public function defaultTierId(Plan $plan, int $units, ?int $currentMaxUnits = null): ?int
    {
        if (!$plan->isTiered()) {
            return null;
        }

        $eligible = collect($this->tierOptions($plan, $units))->where('eligible', true);

        // Only a real limit identifies "their" tier; null (no limit) would wrongly
        // match the open-ended top tier and pre-select the most expensive option.
        $current = $currentMaxUnits !== null ? $eligible->firstWhere('max_units', $currentMaxUnits) : null;

        return $current['id']
            ?? $eligible->sortBy('min_units')->first()['id']
            ?? null;
    }

    /**
     * Price of a chosen tier + cycle, or null if the choice isn't valid for a
     * landlord with $units units (wrong plan, too small, no price).
     *
     * @return array{amount: float, currency: string, tier: ?string, tier_id: ?int, max_units: ?int}|null
     */
    public function quoteForTier(Plan $plan, ?int $tierId, string $cycle, int $units): ?array
    {
        $option = collect($this->tierOptions($plan, $units))->firstWhere('id', $plan->isTiered() ? $tierId : null);

        if (!$option || !$option['eligible'] || empty($option['prices'][$cycle])) {
            return null;
        }

        return [
            'amount' => $option['prices'][$cycle],
            'currency' => $plan->currency ?: 'UGX',
            'tier' => $plan->isTiered() ? $option['label'] : null,
            'tier_id' => $option['id'],
            'max_units' => $option['max_units'],
        ];
    }

    public static function tierLabel(PlanTier $tier): string
    {
        return $tier->max_units === null ? "{$tier->min_units}+ units" : "{$tier->min_units}–{$tier->max_units} units";
    }

    /** Plans a landlord can choose from. */
    public function availablePlans(): Collection
    {
        return Plan::with('tiers')->where('is_active', true)->orderBy('name')->get();
    }

    /** Units the landlord manages (drives the tier of a tiered plan). */
    public function unitCount(Landlord $landlord): int
    {
        return DB::table('units')->where('landlord_id', $landlord->id)->count();
    }

    /**
     * Price for a plan + cycle at the landlord's current size, or null if the
     * plan has no tier covering that many units.
     *
     * @return array{amount: float, currency: string, tier: ?string}|null
     */
    public function quote(Plan $plan, string $cycle, int $units): ?array
    {
        if (!isset(self::CYCLES[$cycle])) {
            return null;
        }

        // A landlord with no units yet is priced at the first tier.
        $amount = $plan->priceForUnits(max($units, 1), $cycle);

        if ($amount === null || $amount <= 0) {
            return null;
        }

        $tier = $plan->isTiered() ? $plan->tierForUnitCount(max($units, 1)) : null;

        return [
            'amount' => (float) $amount,
            'currency' => $plan->currency ?: 'UGX',
            'tier' => $tier ? ($tier->max_units === null ? "{$tier->min_units}+ units" : "{$tier->min_units}–{$tier->max_units} units") : null,
        ];
    }

    /**
     * Paid time is added to the end of any time the landlord still has, so
     * paying early never loses days.
     */
    public function nextPeriodEnd(Landlord $landlord, string $cycle): CarbonInterface
    {
        $current = $landlord->current_period_end;
        $start = $current && $current->isFuture() ? $current->copy() : today();

        return $start->addMonthsNoOverflow(self::CYCLES[$cycle]['months']);
    }

    public static function cycleLabel(?string $cycle): string
    {
        return self::CYCLES[$cycle]['label'] ?? ucfirst((string) $cycle);
    }
}
