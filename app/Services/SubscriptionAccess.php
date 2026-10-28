<?php

namespace App\Services;

use App\Models\Landlord;
use App\Support\Settings;
use Carbon\Carbon;

/**
 * What a landlord can do, given where their subscription stands.
 *
 *   full       on trial or paid up (or no end date set)
 *   grace      the trial or paid period has ended; everything still works
 *              for a few more days, with a banner asking them to choose a plan
 *   read_only  grace is over: they can view and export everything, pay for a
 *              plan and take payouts, but not add or change records or send SMS
 *
 * Nothing is ever deleted, and the tenant portal and rent collection keep
 * working throughout. Paying for a plan restores full access straight away.
 *
 * Trial and grace lengths are platform settings staff can change.
 */
class SubscriptionAccess
{
    public const FULL = 'full';
    public const GRACE = 'grace';
    public const READ_ONLY = 'read_only';

    public const TRIAL_DAYS_KEY = 'subscription.trial_days';
    public const GRACE_DAYS_KEY = 'subscription.grace_days';

    public static function trialDays(): int
    {
        return max(1, Settings::int(self::TRIAL_DAYS_KEY, 14));
    }

    public static function graceDays(): int
    {
        return max(0, Settings::int(self::GRACE_DAYS_KEY, 7));
    }

    /** The day a trial starting today ends. */
    public static function trialEndsOn(): Carbon
    {
        return today()->addDays(self::trialDays());
    }

    /** The last day of grace: set by staff, or the default number of days after the period ends. */
    public static function graceEndsOn(Landlord $landlord): ?Carbon
    {
        if ($landlord->grace_ends_at) {
            return $landlord->grace_ends_at->copy();
        }

        return $landlord->current_period_end?->copy()->addDays(self::graceDays());
    }

    public static function level(Landlord $landlord): string
    {
        $end = $landlord->current_period_end;

        if ($end === null) {
            // A cancelled account with no paid period left has nothing to run on.
            return $landlord->subscription_status === 'cancelled' ? self::READ_ONLY : self::FULL;
        }
        if ($end->gte(today())) {
            return self::FULL;
        }

        return self::graceEndsOn($landlord)->gte(today()) ? self::GRACE : self::READ_ONLY;
    }
}
