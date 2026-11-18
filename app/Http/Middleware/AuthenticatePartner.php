<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The partner portal (/partners): a signed-in partner user, still active,
 * whose partner isn't suspended or turned down. With "ready", the partner
 * must also be approved and have accepted the partner agreement.
 */
class AuthenticatePartner
{
    public function handle(Request $request, Closure $next, ?string $level = null): Response
    {
        $guard = Auth::guard('partner');
        if (!$guard->check()) {
            return redirect()->guest(route('partner.login'));
        }

        $user = $guard->user();
        $partner = $user->partner;
        if (!$user->is_active || !$partner || in_array($partner->status, ['suspended', 'rejected'], true)) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('partner.login')->with('error', $partner?->status === 'rejected'
                ? 'Your partner application was not approved. Contact Primora for details.'
                : 'This partner account is not active. Contact Primora.');
        }

        Auth::shouldUse('partner');

        if ($user->must_change_password && !$request->routeIs('partner.password*', 'partner.logout')) {
            return redirect()->route('partner.password')->with('error', 'Please choose your own password first.');
        }
        if ($level === 'ready') {
            if (!$partner->agreement_accepted_at) {
                return redirect()->route('partner.agreement');
            }
            if (!$partner->isActive()) {
                return redirect()->route('partner.dashboard')->with('error', 'Your partner account is awaiting approval by Primora.');
            }
        }

        return $next($request);
    }
}
