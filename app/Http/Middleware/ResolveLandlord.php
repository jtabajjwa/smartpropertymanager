<?php

namespace App\Http\Middleware;

use App\Models\Landlord;
use App\Models\StaffUser;
use App\Services\CurrentLandlord;
use App\Services\StaffActingSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResolveLandlord
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // Staff working in a landlord's portal on their behalf ("staff mode").
        if ($user instanceof StaffUser) {
            // Re-checked on every request, so revoking access takes effect at once.
            if (!$user->hasPermission('portal.access')) {
                StaffActingSession::stop();

                return redirect()->route('staff.dashboard')
                    ->with('error', "You don't have permission to open landlord portals.");
            }

            $landlordId = StaffActingSession::landlordId();

            if (!$landlordId || !Landlord::whereKey($landlordId)->exists()) {
                StaffActingSession::stop();

                return redirect()->route('staff.landlords.index')
                    ->with('error', 'Choose a landlord and use "Open landlord portal" to work on their account.');
            }

            CurrentLandlord::set($landlordId);

            return $next($request);
        }

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
