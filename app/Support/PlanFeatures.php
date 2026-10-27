<?php

namespace App\Support;

use App\Models\Landlord;
use App\Models\Plan;
use App\Services\CurrentLandlord;

/**
 * Features that subscription plans can include or leave out. Everything not
 * listed here (properties, tenants, invoices, payments, the tenant portal,
 * collections, payouts, SMS) is on every plan.
 *
 * A plan with no feature list includes everything. A landlord with no plan
 * (e.g. on trial) has everything. Staff can grant a landlord extra features.
 */
class PlanFeatures
{
    /** key => [name, what it gives] */
    public const FEATURES = [
        'books' => ['Books & taxes', 'Income statement, income by property, taxes and levies, and the annual tax pack.'],
        'books_advanced' => ['Balance sheet & trial balance', 'Chart of accounts, account ledgers, trial balance and balance sheet (also added to the tax pack).'],
        'accrual_basis' => ['Accrual accounting', 'Keep books on the accrual basis: income when invoiced, with receivables and bad debts.'],
        'team' => ['Team members', 'Add more people to your account, with their own sign-in.'],
        'audit_log' => ['Audit log', 'See who changed what, and when.'],
        'vendors' => ['Vendors & job cards', 'Keep your vendors and track repair and maintenance jobs.'],
    ];

    public static function name(string $key): string
    {
        return self::FEATURES[$key][0] ?? $key;
    }

    /** Whether the signed-in landlord's account has a feature. */
    public static function allows(string $key): bool
    {
        $landlord = self::landlord();

        return !$landlord || $landlord->hasFeature($key);
    }

    /** The current landlord (with plan), loaded once per request. */
    public static function landlord(): ?Landlord
    {
        $id = CurrentLandlord::id();
        if (!$id) {
            return null;
        }

        $attributes = request()->attributes;
        $cached = $attributes->get('plan_features.landlord');
        if ($cached?->id === $id) {
            return $cached;
        }

        $landlord = Landlord::with('plan')->find($id);
        $attributes->set('plan_features.landlord', $landlord);

        return $landlord;
    }

    /** Active plans that include a feature, cheapest-looking first, for the upgrade prompt. */
    public static function plansWith(string $key)
    {
        return Plan::where('is_active', true)->orderBy('id')->get()->filter(fn (Plan $p) => $p->includes($key))->values();
    }

    /** Store a ticked list: every feature ticked = null (includes future features too). */
    public static function normalise(?array $keys): ?array
    {
        $keys = array_values(array_intersect(array_keys(self::FEATURES), $keys ?? []));

        return count($keys) === count(self::FEATURES) ? null : $keys;
    }
}
