<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Payments\Msisdn;
use App\Services\CurrentLandlord;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant portal: requires a signed-in tenant (see TenantPortal\AuthController)
 * and scopes the request to that tenant's landlord.
 *
 * The tenant is signed out if their landlord's account is no longer active,
 * or the phone they verified is no longer on their tenant record.
 */
class AuthenticateTenant
{
    public const SESSION = 'tenant_portal';

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session()->get(self::SESSION, []);
        $tenant = !empty($session['tenant_id']) ? Tenant::withoutGlobalScopes()->with('landlord')->find($session['tenant_id']) : null;

        $valid = $tenant
            && $tenant->landlord?->status === 'active'
            && in_array($session['msisdn'] ?? null, [Msisdn::normalize($tenant->primary_phone), Msisdn::normalize($tenant->secondary_phone)], true);

        if (!$valid) {
            $request->session()->forget(self::SESSION);

            return redirect()->route('tenant.login')->with('error', $tenant ? 'Please sign in again.' : null);
        }

        CurrentLandlord::set($tenant->landlord_id);
        $request->attributes->set('tenant', $tenant);
        View::share('portalTenant', $tenant);
        View::share('portalAccounts', count($session['tenant_ids'] ?? []));

        return $next($request);
    }
}
