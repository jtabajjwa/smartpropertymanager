<?php

namespace App\Http\Middleware;

use App\Models\Landlord;
use App\Services\CurrentLandlord;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets staff work inside one landlord's data: scopes every landlord-owned
 * model to the {landlord} in the URL for the duration of the request.
 *
 * Child records in these routes (estates, units, …) are looked up in the
 * controller rather than via route-model binding, because bindings resolve
 * before this middleware runs, when no landlord context exists yet.
 */
class ResolveStaffLandlordContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $landlord = $request->route('landlord');

        if (!$landlord instanceof Landlord) {
            abort(404);
        }

        CurrentLandlord::set($landlord->id);

        try {
            return $next($request);
        } finally {
            CurrentLandlord::clear();
        }
    }
}
