<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class StaffAdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::guard('staff')->check() || !Auth::guard('staff')->user()->isAdmin()) {
            abort(403, 'Unauthorized. Admin access required.');
        }

        return $next($request);
    }
}
