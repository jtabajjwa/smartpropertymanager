<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ApiTenant;
use App\Models\Tenant;
use App\Services\CurrentLandlord;
use App\Services\TenantPortal\RentPayments;
use App\Services\TenantPortal\TenantAccount;
use App\Services\TenantPortal\TenantLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/**
 * Tenant sign-in for the mobile app, as on the web tenant portal: phone
 * number, email address or unit ID → a 6-digit code by SMS or email →
 * (pick a tenancy, if that phone or email is on several) → a token.
 *
 * The web keeps the sign-in in progress in the session; here each step
 * hands the app a short-lived sealed (encrypted) ticket for the next one,
 * so the phone number or email behind a unit ID is never shown in full.
 */
class AuthController extends Controller
{
    protected const TICKET_MINUTES = 15;

    public function __construct(protected TenantLogin $login)
    {
    }

    public function lookup(Request $request)
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:255']], [
            'identifier.required' => 'Enter your phone number, email address or unit ID.',
        ]);

        $destinations = $this->login->destinations($data['identifier']);

        if (!$destinations) {
            throw ValidationException::withMessages(['identifier' => str_contains($data['identifier'], '@')
                ? "We couldn't find a tenant with that email address. Check it, or sign in with your phone number."
                : "We couldn't find a tenant with that phone number or unit ID. Check it (your unit ID is on your invoice), or ask your landlord to confirm the phone number they have for you."]);
        }

        if (count($destinations) > 1) {
            return response()->json([
                'choices' => array_values($destinations),
                'choice_ticket' => $this->seal('choose', ['keys' => array_keys($destinations)]),
            ]);
        }

        return $this->send($request, ...TenantLogin::parseKey(array_key_first($destinations)));
    }

    /** A unit ID matched more than one current tenant: which phone (or email) is yours. */
    public function choose(Request $request)
    {
        $data = $request->validate(['choice_ticket' => ['required', 'string'], 'choice' => ['required', 'integer', 'min:0']]);
        $keys = $this->unseal($data['choice_ticket'], 'choose')['keys'] ?? [];
        $target = TenantLogin::parseKey($keys[$data['choice']] ?? '');

        if (!$target) {
            throw ValidationException::withMessages(['choice' => 'Please start again.']);
        }

        return $this->send($request, ...$target);
    }

    public function resend(Request $request)
    {
        $data = $request->validate(['login_ticket' => ['required', 'string']]);
        ['channel' => $channel, 'to' => $to] = $this->unseal($data['login_ticket'], 'code');

        if ($error = $this->login->sendCode($channel, $to, $request->ip())) {
            throw ValidationException::withMessages(['code' => $error]);
        }

        return response()->json(['message' => 'A new code has been sent to '.TenantLogin::label($channel, $to).'.']);
    }

    public function verify(Request $request)
    {
        $data = $request->validate([
            'login_ticket' => ['required', 'string'],
            'code' => ['required', 'string', 'max:10'],
            'device_name' => ['required', 'string', 'max:100'],
        ], ['code.required' => 'Enter the 6-digit code we sent you.']);
        ['channel' => $channel, 'to' => $to] = $this->unseal($data['login_ticket'], 'code');

        if (!$this->login->verify($channel, $to, $data['code'])) {
            $where = $channel === TenantLogin::EMAIL ? 'email' : 'SMS';
            throw ValidationException::withMessages(['code' => "That code isn't right or has expired. Check the {$where}, or ask for a new code."]);
        }

        $tenants = $this->login->tenantsFor($channel, $to);
        if ($tenants->isEmpty()) {
            throw ValidationException::withMessages(['code' => 'Your tenant record is no longer available. Please contact your landlord.']);
        }
        if ($tenants->count() === 1) {
            return $this->signIn($tenants->first(), $channel, $to, $data['device_name']);
        }

        // One phone or email, several tenancies (e.g. renting from two landlords): pick one.
        return response()->json([
            'accounts' => $this->accountList($tenants),
            'account_ticket' => $this->seal('account', ['channel' => $channel, 'to' => $to]),
        ]);
    }

    public function select(Request $request)
    {
        $data = $request->validate([
            'account_ticket' => ['required', 'string'],
            'tenant_id' => ['required', 'integer'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);
        ['channel' => $channel, 'to' => $to] = $this->unseal($data['account_ticket'], 'account');

        $tenant = $this->login->tenantsFor($channel, $to)->firstWhere('id', $data['tenant_id']);
        if (!$tenant) {
            throw ValidationException::withMessages(['tenant_id' => 'Please sign in again.']);
        }

        return $this->signIn($tenant, $channel, $to, $data['device_name']);
    }

    public function me(Request $request)
    {
        return response()->json($this->profile($request->user()));
    }

    /** The other tenancies the same phone or email signs in to. */
    public function accounts(Request $request)
    {
        [$channel, $to] = ApiTenant::signedInWith($request->user()->currentAccessToken()->abilities);

        return response()->json(['current' => $request->user()->id, 'accounts' => $this->accountList($this->login->tenantsFor($channel, $to))]);
    }

    /** Switch to another of those tenancies: a new token replaces this one. */
    public function switchAccount(Request $request)
    {
        $data = $request->validate(['tenant_id' => ['required', 'integer']]);
        $token = $request->user()->currentAccessToken();
        [$channel, $to] = ApiTenant::signedInWith($token->abilities);

        $tenant = $this->login->tenantsFor($channel, $to)->firstWhere('id', $data['tenant_id']);
        if (!$tenant) {
            throw ValidationException::withMessages(['tenant_id' => 'That account is not available.']);
        }

        $name = $token->name;
        \App\Http\Controllers\Api\V1\DeviceController::forgetSignIn($token);
        $token->delete();

        return $this->signIn($tenant, $channel, $to, $name);
    }

    public function logout(Request $request)
    {
        \App\Http\Controllers\Api\V1\DeviceController::forgetSignIn($request->user()->currentAccessToken());
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    protected function send(Request $request, string $channel, string $to)
    {
        $ticket = $this->seal('code', ['channel' => $channel, 'to' => $to]);

        if ($error = $this->login->sendCode($channel, $to, $request->ip())) {
            // A code may still be valid from a moment ago: let them enter it.
            if (!str_contains($error, 'less than a minute')) {
                throw ValidationException::withMessages(['identifier' => $error]);
            }

            return response()->json(['login_ticket' => $ticket, 'channel' => $channel, 'sent_to' => TenantLogin::label($channel, $to), 'message' => $error]);
        }

        $message = 'We sent a 6-digit code to '.TenantLogin::label($channel, $to).'.';
        if ($channel === TenantLogin::EMAIL) {
            $message .= " If it isn't in your inbox, check your spam folder.";
        }
        if (app()->environment('local') && $channel === TenantLogin::SMS && !config('services.marz_sms.enabled')) {
            $message .= ' (Development: SMS is off, so the code is in storage/logs/laravel.log.)';
        }

        return response()->json(['login_ticket' => $ticket, 'channel' => $channel, 'sent_to' => TenantLogin::label($channel, $to), 'message' => $message]);
    }

    protected function signIn(Tenant $tenant, string $channel, string $to, string $device)
    {
        // One token per device: signing in again on the same device replaces it.
        $tenant->tokens()->where('name', $device)->delete();
        $token = $tenant->createToken($device, ['tenant', ApiTenant::SIGNIN.TenantLogin::key($channel, $to)])->plainTextToken;

        return response()->json(['token' => $token] + $this->profile($tenant, $channel, $to));
    }

    protected function profile(Tenant $tenant, ?string $channel = null, ?string $to = null): array
    {
        if (!$channel) {
            [$channel, $to] = ApiTenant::signedInWith($tenant->currentAccessToken()?->abilities ?? []);
        }
        $landlord = $tenant->landlord;
        CurrentLandlord::set($tenant->landlord_id);

        return [
            'role' => 'tenant',
            'tenant' => [
                'id' => $tenant->id,
                'number' => $tenant->number,
                'name' => $tenant->name,
                'phone' => $tenant->primary_phone,
                'email' => $tenant->email,
            ],
            // Unit IDs, which tenants key in when paying by USSD (and can sign in with).
            'units' => $tenant->leases()->where('status', 'active')->with('unit.estate')->get()
                ->filter(fn ($l) => $l->unit?->code)
                ->map(fn ($l) => ['unit' => AccountController::lease($l)['unit'], 'code' => $l->unit->code])->values(),
            'landlord' => [
                'name' => $landlord->name,
                'logo_url' => $landlord->logoUrl(),
                'phone' => $landlord->contact_phone,
                'email' => $landlord->contact_email,
            ],
            'signed_in_with' => $channel ? TenantLogin::label($channel, $to) : null,
            'account_count' => $channel ? $this->login->tenantsFor($channel, $to)->count() : 1,
            'multi_unit' => (new TenantAccount($tenant))->hasMultipleUnits(),
            'can_pay' => app(RentPayments::class)->available($tenant),
        ];
    }

    protected function accountList(Collection $tenants): array
    {
        $tenants->load(['leases' => fn ($q) => $q->withoutGlobalScopes()->with(['unit' => fn ($q) => $q->withoutGlobalScopes()->with(['estate' => fn ($q) => $q->withoutGlobalScopes()])])->latest('start_date')]);

        return $tenants->map(fn (Tenant $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'landlord' => $t->landlord?->name,
            'units' => $t->leases->map(fn ($l) => trim(($l->unit?->estate?->name ? $l->unit->estate->name.' · ' : '').$l->unit?->name))->filter()->unique()->values(),
        ])->values()->all();
    }

    protected function seal(string $kind, array $data): string
    {
        return Crypt::encryptString(json_encode(['kind' => $kind, 'exp' => now()->addMinutes(self::TICKET_MINUTES)->timestamp] + $data));
    }

    protected function unseal(string $ticket, string $kind): array
    {
        try {
            $data = json_decode(Crypt::decryptString($ticket), true);
        } catch (\Throwable) {
            $data = null;
        }

        if (!is_array($data) || ($data['kind'] ?? null) !== $kind || ($data['exp'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['code' => 'This sign-in has expired. Please start again.']);
        }

        return $data;
    }
}
