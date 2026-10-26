<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard for staff permissions, e.g. ->middleware('staff.can:subscriptions.adjust').
 * Several permissions separated by "|" means any one of them is enough.
 */
class StaffCan
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $staff = Auth::guard('staff')->user();

        $allowed = $staff && collect(explode('|', $permissions))->contains(fn ($p) => $staff->hasPermission($p));

        if (!$allowed) {
            abort(403, "You don't have permission to do this. Ask a staff admin if you need it.");
        }

        return $next($request);
    }
}
