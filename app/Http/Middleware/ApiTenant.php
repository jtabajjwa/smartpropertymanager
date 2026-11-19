<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\CurrentLandlord;
use App\Services\TenantPortal\TenantLogin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mobile app's tenant API (after auth:sanctum), with the web tenant
 * portal's rules: the token belongs to a tenant, their landlord's account is
 * active, and the phone or email they signed in with is still on their
 * record (otherwise the token is revoked: code "signed_out").
 */
class ApiTenant
{
    /** The token's ability recording how the tenant signed in: "signin:sms:2567…" / "signin:email:…". */
    public const SIGNIN = 'signin:';

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->user();
        if (!$tenant instanceof Tenant) {
            return response()->json(['message' => 'This part of the app is for tenants.', 'code' => 'not_tenant'], 403);
        }

        [$via, $to] = self::signedInWith($tenant->currentAccessToken()?->abilities ?? []);
        $tenant->loadMissing('landlord');

        if ($tenant->landlord?->status !== 'active' || !$via || !TenantLogin::matches($tenant, $via, $to)) {
            $tenant->currentAccessToken()?->delete();

            return response()->json(['message' => 'Please sign in again.', 'code' => 'signed_out'], 401);
        }

        CurrentLandlord::set($tenant->landlord_id);
        // As AuthenticateTenant does, so the web portal's document downloads serve the app too.
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }

    /** @return array{0: ?string, 1: ?string} [channel, destination] from a tenant token's abilities */
    public static function signedInWith(array $abilities): array
    {
        foreach ($abilities as $ability) {
            if (str_starts_with($ability, self::SIGNIN) && ($parsed = TenantLogin::parseKey(substr($ability, strlen(self::SIGNIN))))) {
                return $parsed;
            }
        }

        return [null, null];
    }
}
