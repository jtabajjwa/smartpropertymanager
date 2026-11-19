<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The mobile API's admin-only sections (SMS, books, payouts, users), as on the web; after api.landlord. */
class ApiAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->isAdmin()) {
            return response()->json(['message' => 'Only your organisation\'s admins can do this.', 'code' => 'admin_only'], 403);
        }

        return $next($request);
    }
}
