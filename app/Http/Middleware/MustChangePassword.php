<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class MustChangePassword
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->must_change_password) {
            $allowed = ['change-password', 'logout'];
            if (!in_array($request->route()->getName(), $allowed)) {
                return redirect()->route('change-password');
            }
        }

        return $next($request);
    }
}
