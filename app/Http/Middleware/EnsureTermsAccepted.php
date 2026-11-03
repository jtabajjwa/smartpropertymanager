<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A landlord must have accepted the current Terms of Service (config/legal.php).
 * Landlords who signed up accept them on the sign-up form; landlords created
 * by staff, or whose accepted version is out of date, are sent to accept them
 * once. Only the landlord's admins can accept for the company; other team
 * members carry on, as do staff in staff mode.
 */
class EnsureTermsAccepted
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isLandlordAdmin() && $user->landlord && !$user->landlord->hasAcceptedCurrentTerms()) {
            if ($request->expectsJson()) {
                abort(403, 'Please accept the updated Terms of Service first.');
            }

            return redirect()->guest(route('terms.show'));
        }

        return $next($request);
    }
}
