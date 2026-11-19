<?php

namespace App\Http\Middleware;

use App\Support\PlanFeatures;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Mobile API sections that need a plan feature (e.g. api.feature:books), after api.landlord. */
class ApiFeature
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        foreach ($features as $feature) {
            if (!PlanFeatures::allows($feature)) {
                return response()->json(['message' => PlanFeatures::name($feature).' is not included in your plan.', 'code' => 'feature_unavailable'], 403);
            }
        }

        return $next($request);
    }
}
