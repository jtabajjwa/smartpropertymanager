<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class StaffMustChangePassword
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('staff')->check() && Auth::guard('staff')->user()->must_change_password) {
            $allowed = ['staff.change-password', 'staff.logout'];
            if (!in_array($request->route()->getName(), $allowed)) {
                return redirect()->route('staff.change-password');
            }
        }

        return $next($request);
    }
}
