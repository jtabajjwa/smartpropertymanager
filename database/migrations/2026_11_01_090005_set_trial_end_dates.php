<?php

use App\Services\SubscriptionAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trials used to have no end date, so they never ended. Existing trials get
 * a full trial from today (see SubscriptionAccess::trialDays); new sign-ups
 * get one when they are created.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('landlords')->where('subscription_status', 'trialing')->whereNull('current_period_end')
            ->update(['current_period_end' => SubscriptionAccess::trialEndsOn()->toDateString()]);
    }

    public function down(): void
    {
        // Leaves the dates: they can't be told apart from ones staff set by hand.
    }
};
