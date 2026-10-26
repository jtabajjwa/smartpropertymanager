<?php

namespace App\Http\Controllers\TenantPortal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateTenant;
use App\Services\TenantPortal\TenantLogin;
use Illuminate\Http\Request;

/**
 * Tenant sign-in: phone or unit number → SMS code → (pick a tenancy if the
 * phone is on more than one tenant record) → portal.
 */
class AuthController extends Controller
{
    /** Session key for a sign-in in progress: msisdn (+ choices before one is picked). */
    protected const PENDING = 'tenant_login';

    public function __construct(protected TenantLogin $login)
    {
    }

    public function show(Request $request)
    {
        if (!empty($request->session()->get(AuthenticateTenant::SESSION)['tenant_id'])) {
            return redirect()->route('tenant.dashboard');
        }

        return view('tenant-portal.auth.login');
    }

    public function lookup(Request $request)
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:50']], [
            'identifier.required' => 'Enter your phone number or unit number.',
        ]);

        $destinations = $this->login->destinations($data['identifier']);

        if (!$destinations) {
            return back()->withInput()->withErrors(['identifier' => "We couldn't find a tenant with that phone or unit number. Check it, or ask your landlord to confirm the phone number they have for you."]);
        }

        if (count($destinations) > 1) {
            $request->session()->put(self::PENDING, ['choices' => $destinations]);

            return redirect()->route('tenant.login.choose');
        }

        return $this->send($request, array_key_first($destinations));
    }

    /** A unit number matched more than one tenant: pick your phone. */
    public function choose(Request $request)
    {
        $choices = $request->session()->get(self::PENDING)['choices'] ?? null;

        if (!$choices) {
            return redirect()->route('tenant.login');
        }

        return view('tenant-portal.auth.choose', ['choices' => array_values($choices)]);
    }

    public function chosen(Request $request)
    {
        $choices = $request->session()->get(self::PENDING)['choices'] ?? [];
        $index = (int) $request->input('choice', -1);
        $msisdn = array_keys($choices)[$index] ?? null;

        if (!$msisdn) {
            return redirect()->route('tenant.login');
        }

        return $this->send($request, $msisdn);
    }

    public function showVerify(Request $request)
    {
        $msisdn = $request->session()->get(self::PENDING)['msisdn'] ?? null;

        if (!$msisdn) {
            return redirect()->route('tenant.login');
        }

        return view('tenant-portal.auth.verify', ['masked' => TenantLogin::mask($msisdn)]);
    }

    public function resend(Request $request)
    {
        $msisdn = $request->session()->get(self::PENDING)['msisdn'] ?? null;

        if (!$msisdn) {
            return redirect()->route('tenant.login');
        }

        $error = $this->login->sendCode($msisdn, $request->ip());

        return redirect()->route('tenant.verify')->with($error ? 'error' : 'success', $error ?? 'A new code has been sent.');
    }

    public function verify(Request $request)
    {
        $msisdn = $request->session()->get(self::PENDING)['msisdn'] ?? null;

        if (!$msisdn) {
            return redirect()->route('tenant.login');
        }

        $request->validate(['code' => ['required', 'string', 'max:10']], ['code.required' => 'Enter the 6-digit code we sent you.']);

        if (!$this->login->verify($msisdn, (string) $request->input('code'))) {
            return back()->withErrors(['code' => "That code isn't right or has expired. Check the SMS, or ask for a new code."]);
        }

        $tenants = $this->login->tenantsWithPhone($msisdn);

        if ($tenants->isEmpty()) {
            return redirect()->route('tenant.login')->with('error', 'Your tenant record is no longer available. Please contact your landlord.');
        }

        $request->session()->forget(self::PENDING);
        $request->session()->regenerate();
        $request->session()->put(AuthenticateTenant::SESSION, [
            'msisdn' => $msisdn,
            'tenant_ids' => $tenants->pluck('id')->all(),
            'tenant_id' => $tenants->count() === 1 ? $tenants->first()->id : null,
        ]);

        return $tenants->count() === 1 ? redirect()->route('tenant.dashboard') : redirect()->route('tenant.accounts');
    }

    /** One phone, several tenancies (e.g. renting from two landlords): pick one. */
    public function accounts(Request $request)
    {
        $session = $request->session()->get(AuthenticateTenant::SESSION);

        if (empty($session['tenant_ids'])) {
            return redirect()->route('tenant.login');
        }

        $tenants = $this->login->tenantsWithPhone($session['msisdn'])->whereIn('id', $session['tenant_ids'])->values();
        $tenants->load(['leases' => fn ($q) => $q->withoutGlobalScopes()->with(['unit' => fn ($q) => $q->withoutGlobalScopes()])->latest('start_date')]);

        return view('tenant-portal.auth.accounts', ['tenants' => $tenants, 'current' => $session['tenant_id'] ?? null]);
    }

    public function selectAccount(Request $request)
    {
        $session = $request->session()->get(AuthenticateTenant::SESSION);
        $id = (int) $request->input('tenant_id');

        if (empty($session['tenant_ids']) || !in_array($id, $session['tenant_ids'], true)) {
            return redirect()->route('tenant.login');
        }

        $session['tenant_id'] = $id;
        $request->session()->put(AuthenticateTenant::SESSION, $session);

        return redirect()->route('tenant.dashboard');
    }

    public function logout(Request $request)
    {
        $request->session()->forget([AuthenticateTenant::SESSION, self::PENDING]);
        $request->session()->regenerate();

        return redirect()->route('tenant.login')->with('success', 'You have signed out.');
    }

    protected function send(Request $request, string $msisdn)
    {
        if ($error = $this->login->sendCode($msisdn, $request->ip())) {
            // A code may still be valid from a moment ago: let them enter it.
            if (str_contains($error, 'less than a minute')) {
                $request->session()->put(self::PENDING, ['msisdn' => $msisdn]);

                return redirect()->route('tenant.verify')->with('error', $error);
            }

            return back()->withInput()->with('error', $error);
        }

        $request->session()->put(self::PENDING, ['msisdn' => $msisdn]);

        $notice = 'We sent a 6-digit code to '.TenantLogin::mask($msisdn).'.';
        if (app()->environment('local') && !config('services.marz_sms.enabled')) {
            $notice .= ' (Development: SMS is off, so the code is in storage/logs/laravel.log.)';
        }

        return redirect()->route('tenant.verify')->with('success', $notice);
    }
}
