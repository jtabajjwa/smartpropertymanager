<?php

namespace App\Http\Middleware;

use App\Services\CurrentLandlord;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResolveLandlord
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && empty($user->landlord_id)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('error', 'Your account is not linked to an organization. Contact an administrator.');
        }

        CurrentLandlord::set($user?->landlord_id);

        return $next($request);
    }
}
