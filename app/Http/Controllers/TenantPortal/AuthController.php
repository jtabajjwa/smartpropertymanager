<?php

namespace App\Http\Controllers\TenantPortal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateTenant;
use App\Services\TenantPortal\TenantLogin;
use Illuminate\Http\Request;

/**
 * Tenant sign-in: phone number, email address or unit number → a code by
 * SMS (phone) or email → (pick a tenancy if that phone or email is on more
 * than one tenant record) → portal.
 */
class AuthController extends Controller
{
    /** Session key for a sign-in in progress: channel + destination (+ choices before one is picked). */
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
        $data = $request->validate(['identifier' => ['required', 'string', 'max:255']], [
            'identifier.required' => 'Enter your phone number, email address or unit number.',
        ]);

        $destinations = $this->login->destinations($data['identifier']);

        if (!$destinations) {
            $error = str_contains($data['identifier'], '@')
                ? "We couldn't find a tenant with that email address. Check it, or sign in with your phone number."
                : "We couldn't find a tenant with that phone or unit number. Check it, or ask your landlord to confirm the phone number they have for you.";

            return back()->withInput()->withErrors(['identifier' => $error]);
        }

        if (count($destinations) > 1) {
            $request->session()->put(self::PENDING, ['choices' => $destinations]);

            return redirect()->route('tenant.login.choose');
        }

        return $this->send($request, ...TenantLogin::parseKey(array_key_first($destinations)));
    }

    /** A unit number matched more than one tenant: pick your phone (or email). */
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
        $target = TenantLogin::parseKey(array_keys($choices)[$index] ?? '');

        if (!$target) {
            return redirect()->route('tenant.login');
        }

        return $this->send($request, ...$target);
    }

    public function showVerify(Request $request)
    {
        if (!$pending = $this->pending($request)) {
            return redirect()->route('tenant.login');
        }

        return view('tenant-portal.auth.verify', [
            'channel' => $pending['channel'],
            'masked' => TenantLogin::label($pending['channel'], $pending['to']),
        ]);
    }

    public function resend(Request $request)
    {
        if (!$pending = $this->pending($request)) {
            return redirect()->route('tenant.login');
        }

        $error = $this->login->sendCode($pending['channel'], $pending['to'], $request->ip());

        return redirect()->route('tenant.verify')->with($error ? 'error' : 'success', $error ?? 'A new code has been sent.');
    }

    public function verify(Request $request)
    {
        if (!$pending = $this->pending($request)) {
            return redirect()->route('tenant.login');
        }

        $request->validate(['code' => ['required', 'string', 'max:10']], ['code.required' => 'Enter the 6-digit code we sent you.']);

        if (!$this->login->verify($pending['channel'], $pending['to'], (string) $request->input('code'))) {
            $where = $pending['channel'] === TenantLogin::EMAIL ? 'email' : 'SMS';

            return back()->withErrors(['code' => "That code isn't right or has expired. Check the {$where}, or ask for a new code."]);
        }

        $tenants = $this->login->tenantsFor($pending['channel'], $pending['to']);

        if ($tenants->isEmpty()) {
            return redirect()->route('tenant.login')->with('error', 'Your tenant record is no longer available. Please contact your landlord.');
        }

        $request->session()->forget(self::PENDING);
        $request->session()->regenerate();
        $request->session()->put(AuthenticateTenant::SESSION, [
            'via' => $pending['channel'],
            'to' => $pending['to'],
            'tenant_ids' => $tenants->pluck('id')->all(),
            'tenant_id' => $tenants->count() === 1 ? $tenants->first()->id : null,
        ]);

        return $tenants->count() === 1 ? redirect()->route('tenant.dashboard') : redirect()->route('tenant.accounts');
    }

    /** One phone (or email), several tenancies (e.g. renting from two landlords): pick one. */
    public function accounts(Request $request)
    {
        $session = $request->session()->get(AuthenticateTenant::SESSION);

        if (empty($session['tenant_ids'])) {
            return redirect()->route('tenant.login');
        }

        [$via, $to] = AuthenticateTenant::signedInWith($session);
        $tenants = $this->login->tenantsFor($via, (string) $to)->whereIn('id', $session['tenant_ids'])->values();
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

    /** @return array{channel: string, to: string}|null the sign-in in progress */
    protected function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::PENDING);

        return isset($pending['channel'], $pending['to']) ? $pending : null;
    }

    protected function send(Request $request, string $channel, string $to)
    {
        if ($error = $this->login->sendCode($channel, $to, $request->ip())) {
            // A code may still be valid from a moment ago: let them enter it.
            if (str_contains($error, 'less than a minute')) {
                $request->session()->put(self::PENDING, ['channel' => $channel, 'to' => $to]);

                return redirect()->route('tenant.verify')->with('error', $error);
            }

            return back()->withInput()->with('error', $error);
        }

        $request->session()->put(self::PENDING, ['channel' => $channel, 'to' => $to]);

        $notice = 'We sent a 6-digit code to '.TenantLogin::label($channel, $to).'.';
        if ($channel === TenantLogin::EMAIL) {
            $notice .= " If it isn't in your inbox, check your spam folder.";
        }
        if (app()->environment('local')) {
            if ($channel === TenantLogin::SMS && !config('services.marz_sms.enabled')) {
                $notice .= ' (Development: SMS is off, so the code is in storage/logs/laravel.log.)';
            } elseif ($channel === TenantLogin::EMAIL && config('mail.default') === 'log') {
                $notice .= ' (Development: email goes to storage/logs/laravel.log.)';
            }
        }

        return redirect()->route('tenant.verify')->with('success', $notice);
    }
}
