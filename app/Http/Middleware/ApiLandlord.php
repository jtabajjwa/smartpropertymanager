<?php

namespace App\Http\Middleware;

use App\Models\Landlord;
use App\Models\User;
use App\Services\CurrentLandlord;
use App\Support\PlanFeatures;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mobile app's landlord API (after auth:sanctum): the same rules as the
 * web portal's middleware, answered as JSON with a machine-readable "code"
 * the app acts on instead of a redirect:
 *
 *   account_inactive          the user was switched off (token revoked)
 *   no_landlord               not linked to an organisation
 *   email_unverified          verify the email address first
 *   password_change_required  choose a new password (only /auth routes allowed)
 *   terms_required            accept the current Terms (only /auth routes allowed)
 *   subscription_read_only    a lapsed subscription: reading only
 *   feature_unavailable       not in the landlord's plan (api.landlord:books)
 */
class ApiLandlord
{
    /** Routes a user may call before changing their password or accepting the terms. */
    protected const SETUP_ROUTES = ['api.v1.auth.*'];

    /** Writes allowed while the subscription is read-only (paying for it, mainly). */
    protected const READ_ONLY_ALLOWED = ['api.v1.auth.*', 'api.v1.subscription.*', 'api.v1.sms.buy', 'api.v1.payouts.*'];

    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->deny('This app is for landlords and their staff.', 'not_landlord_user', 403);
        }
        if (!$user->isActive()) {
            $user->currentAccessToken()?->delete();

            return $this->deny('Your account has been deactivated. Contact your administrator.', 'account_inactive', 401);
        }
        if (!$user->landlord_id || !($landlord = Landlord::find($user->landlord_id))) {
            return $this->deny('Your account is not linked to an organisation. Contact an administrator.', 'no_landlord', 403);
        }

        CurrentLandlord::set($landlord->id);

        $setup = $request->routeIs(...self::SETUP_ROUTES);
        if (!$setup) {
            if (!$user->hasVerifiedEmail()) {
                return $this->deny('Please verify your email address first. We sent you a link when you signed up.', 'email_unverified', 403);
            }
            if ($user->must_change_password) {
                return $this->deny('Please choose a new password.', 'password_change_required', 403);
            }
            if ($user->isLandlordAdmin() && !$landlord->hasAcceptedCurrentTerms()) {
                return $this->deny('Please accept the updated Terms of Service.', 'terms_required', 403);
            }
        }

        if (!$request->isMethodSafe() && !$request->routeIs(...self::READ_ONLY_ALLOWED) && $landlord->isReadOnly()) {
            return $this->deny(EnforceSubscriptionAccess::message($landlord), 'subscription_read_only', 403);
        }

        foreach ($features as $feature) {
            if (!PlanFeatures::allows($feature)) {
                return $this->deny(PlanFeatures::name($feature).' is not included in your plan.', 'feature_unavailable', 403);
            }
        }

        return $next($request);
    }

    protected function deny(string $message, string $code, int $status): Response
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
