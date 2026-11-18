<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Mail\NoticeMail;
use App\Models\Landlord;
use App\Models\Partner;
use App\Models\PartnerCode;
use App\Models\User;
use App\Rules\OrganisationName;
use App\Rules\PersonName;
use App\Rules\PhoneNumber;
use App\Services\PartnerProgram;
use App\Services\StandardLeaseTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** The partner portal: their landlords, signing landlords up, and their codes. */
class PortalController extends Controller
{
    protected function partner(): Partner
    {
        return Auth::guard('partner')->user()->partner;
    }

    public function dashboard()
    {
        $partner = $this->partner();
        $landlords = Landlord::where('partner_id', $partner->id)->get();
        $states = $landlords->map(fn (Landlord $l) => $l->subscriptionState())->countBy();

        return view('partner.dashboard', [
            'partner' => $partner,
            'total' => $landlords->count(),
            'trial' => ($states['trial'] ?? 0),
            'paying' => ($states['active'] ?? 0) + ($states['expiring'] ?? 0),
            'lapsed' => $landlords->count() - ($states['trial'] ?? 0) - ($states['active'] ?? 0) - ($states['expiring'] ?? 0),
            'thisMonth' => $landlords->filter(fn ($l) => $l->partner_attributed_at?->isCurrentMonth())->count(),
            'recent' => $landlords->sortByDesc('partner_attributed_at')->take(5),
            'code' => $partner->defaultCode(),
            'balances' => app(\App\Services\PartnerCommissions::class)->balances($partner),
            'earnedThisMonth' => (float) $partner->commissions()->where('earned_at', '>=', now()->startOfMonth())->sum('net'),
        ]);
    }

    public function landlords(Request $request)
    {
        $partner = $this->partner();
        $search = trim((string) $request->input('search'));

        $landlords = Landlord::with(['plan', 'partnerCode', 'users' => fn ($q) => $q->where('role', 'admin')->orderBy('id')])
            ->where('partner_id', $partner->id)
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('contact_email', 'like', "%{$search}%")->orWhere('contact_phone', 'like', "%{$search}%")))
            ->latest('partner_attributed_at')->paginate(25)->withQueryString();

        $earned = $partner->commissions()->whereIn('landlord_id', $landlords->pluck('id'))->selectRaw('landlord_id, sum(net) as n')->groupBy('landlord_id')->pluck('n', 'landlord_id');

        return view('partner.landlords', compact('partner', 'landlords', 'search', 'earned'));
    }

    public function createLandlord()
    {
        $partner = $this->partner();

        return view('partner.landlord-create', ['partner' => $partner, 'codes' => $partner->codes()->where('is_active', true)->with('promotion')->get()]);
    }

    /**
     * The partner signs a landlord up. The landlord's admin gets an email to set
     * their own password, and accepts Primora's terms when they first sign in.
     */
    public function storeLandlord(Request $request, PartnerProgram $program)
    {
        $partner = $this->partner();
        foreach (['company_name', 'name'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => preg_replace('/\s+/u', ' ', trim($request->input($field)))]);
            }
        }
        if ($request->has('phone')) {
            $request->merge(['phone' => \App\Services\PhoneFormatter::format($request->phone)]);
        }
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255', new OrganisationName],
            'name' => ['required', 'string', 'max:255', new PersonName],
            'email' => ['required', 'email:rfc,filter', 'max:255', 'unique:users,email', 'unique:landlords,contact_email'],
            'phone' => ['required', 'string', new PhoneNumber, 'unique:users,phone', 'unique:landlords,contact_phone'],
            'code_id' => ['nullable', Rule::exists('partner_codes', 'id')->where('partner_id', $partner->id)->where('is_active', true)],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'Confirm the landlord agreed to be signed up.',
            'email.unique' => 'This email already has a Primora account.',
            'phone.unique' => 'This phone number already has a Primora account.',
        ]);

        $code = isset($data['code_id']) ? PartnerCode::find($data['code_id']) : $partner->ensureDefaultCode();

        $landlord = DB::transaction(function () use ($data, $code, $program) {
            $landlord = Landlord::create([
                'name' => $data['company_name'],
                'contact_email' => $data['email'],
                'contact_phone' => $data['phone'],
                'status' => 'active',
                'subscription_status' => 'trialing',
            ]);
            app(StandardLeaseTemplate::class)->installFor($landlord->id);
            app(\App\Services\SmsCredits::class)->grantStarter($landlord);

            User::create([
                'landlord_id' => $landlord->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => Str::random(40),   // they set their own (see the invitation)
                'role' => 'admin',
                'is_active' => true,
                'must_change_password' => false,
            ]);

            $program->attach($landlord, $code, 'partner');

            return $landlord->fresh('promotion');
        });

        $this->invite($landlord, $data['name'], $partner);

        return redirect()->route('partner.landlords')->with('success', "{$landlord->name} is signed up and linked to you. We've emailed {$data['email']} to set a password.");
    }

    protected function invite(Landlord $landlord, string $name, Partner $partner): void
    {
        try {
            Mail::to($landlord->contact_email)->send(new NoticeMail(
                subject: 'Your '.config('app.name').' account is ready',
                heading: "Welcome to ".config('app.name').", {$name}",
                lines: array_values(array_filter([
                    "{$partner->name} has set up a ".config('app.name')." account for {$landlord->name}, with a free trial.",
                    $landlord->promotion ? 'Your offer: '.$landlord->promotion->summary().'.' : null,
                    'To sign in, set your password: open the link below, enter this email address ('.$landlord->contact_email.') and we\'ll send you a code.',
                    'When you first sign in, you\'ll be asked to accept the Terms of Service. If you didn\'t ask for this account, ignore this email.',
                ])),
                actionText: 'Set my password',
                actionUrl: route('forgot-password'),
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function codes()
    {
        $partner = $this->partner();
        $partner->ensureDefaultCode();

        $codes = $partner->codes()->with('promotion')->withCount('landlords')->orderByDesc('is_default')->orderBy('code')->get();

        return view('partner.codes', ['partner' => $partner->load('defaultPromotion'), 'codes' => $codes]);
    }

    public function profile()
    {
        $partner = $this->partner();

        return view('partner.profile', ['partner' => $partner, 'users' => $partner->users()->orderBy('name')->get()]);
    }
}
