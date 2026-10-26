<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanTier;
use Illuminate\Http\JsonResponse;

/**
 * Published subscription pricing for the marketing website. Read-only and
 * limited to active plans that staff have marked "Show on website"; only
 * public pricing fields are returned.
 *
 * Every plan is expressed as unit tiers, so the website can treat flat and
 * tiered plans alike (a flat plan is one open-ended tier).
 */
class WebsitePlansController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $plans = Plan::with('tiers')
            ->where('is_active', true)
            ->where('show_on_website', true)
            ->orderBy('id')
            ->get();

        return response()->json([
            'plans' => $plans->map(fn (Plan $plan) => [
                'name' => $plan->name,
                'pricing_model' => $plan->pricing_model,
                'currency' => $plan->currency,
                'tiers' => $plan->isTiered()
                    ? $plan->tiers->map(fn (PlanTier $tier) => [
                        'min_units' => $tier->min_units,
                        'max_units' => $tier->max_units,
                        'monthly' => (float) $tier->monthly_price,
                        'quarterly' => (float) $tier->quarterly_price,
                        'annual' => (float) $tier->annual_price,
                    ])->values()
                    : [[
                        'min_units' => 1,
                        'max_units' => null,
                        'monthly' => (float) $plan->monthly_price,
                        'quarterly' => (float) $plan->quarterly_price,
                        'annual' => (float) $plan->annual_price,
                    ]],
            ])->values(),
            'generated_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'private, max-age=300');
    }
}
