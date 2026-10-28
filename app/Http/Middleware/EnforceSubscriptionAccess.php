<?php

namespace App\Http\Middleware;

use App\Models\Landlord;
use App\Services\CurrentLandlord;
use App\Services\StaffActingSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A read-only landlord (trial or plan ended, grace over; see SubscriptionAccess)
 * can look at and export everything, but can't add or change records.
 * Paying for a plan, buying SMS and payouts of rent already collected still
 * work. Staff working in the account on the landlord's behalf are never blocked.
 */
class EnforceSubscriptionAccess
{
    /** Changes allowed while read-only. */
    public const ALLOWED_ROUTES = [
        'subscription.*',
        'sms-credits.*',
        'payouts.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || StaffActingSession::active() || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        $landlord = ($id = CurrentLandlord::id()) ? Landlord::find($id) : null;
        if (!$landlord?->isReadOnly()) {
            return $next($request);
        }

        $message = self::message($landlord);
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->back()->with('error', $message);
    }

    public static function message(Landlord $landlord): string
    {
        $what = $landlord->subscription_status === 'trialing' ? 'free trial' : 'subscription';

        return "Your {$what} has ended, so your account is read-only. Choose a plan to add or change records.";
    }
}
