<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class StaffCheckActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('staff')->check() && !Auth::guard('staff')->user()->isActive()) {
            Auth::guard('staff')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('staff.login')
                ->with('error', 'Your account has been deactivated. Contact an administrator.');
        }

        return $next($request);
    }
}
