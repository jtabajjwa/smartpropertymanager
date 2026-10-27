<?php

namespace App\Http\Middleware;

use App\Support\PlanFeatures;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * feature:books[,books_advanced] — the landlord's plan must include every
 * listed feature. Pages show what the feature does and which plans have it;
 * form posts go back with a message.
 */
class RequirePlanFeature
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        foreach ($features as $feature) {
            if (!PlanFeatures::allows($feature)) {
                if (!$request->isMethod('GET')) {
                    return back()->with('error', PlanFeatures::name($feature).' is not included in your plan.');
                }

                return response()->view('upgrade', [
                    'feature' => $feature,
                    'landlord' => PlanFeatures::landlord(),
                    'plans' => PlanFeatures::plansWith($feature),
                ], 403);
            }
        }

        return $next($request);
    }
}
