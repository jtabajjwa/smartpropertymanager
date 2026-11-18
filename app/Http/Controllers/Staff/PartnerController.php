<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Mail\NoticeMail;
use App\Models\Landlord;
use App\Models\Partner;
use App\Models\PartnerCode;
use App\Models\PartnerUser;
use App\Models\Promotion;
use App\Rules\PhoneNumber;
use App\Services\PartnerProgram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Staff: marketing partners and agents, their codes and sign-ins, and which landlords are theirs. */
class PartnerController extends Controller
{
    public const STATUS_TABS = ['all' => 'All', 'pending' => 'Applications', 'active' => 'Active', 'suspended' => 'Suspended', 'rejected' => 'Not approved'];

    public function index(Request $request)
    {
        $status = array_key_exists((string) $request->input('status'), self::STATUS_TABS) ? $request->input('status') : 'all';
        $search = trim((string) $request->input('search'));

        $partners = Partner::withCount('landlords')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('number', $search)
                ->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")
                ->orWhereHas('codes', fn ($c) => $c->where('code', PartnerCode::normalize($search)))))
            ->orderByRaw("status = 'pending' desc")->latest()->paginate(25)->withQueryString();

        return view('staff.partners.index', [
            'partners' => $partners, 'status' => $status, 'search' => $search,
            'counts' => Partner::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'promotions' => Promotion::orderBy('name')->get(),
        ]);
    }

    /** Staff add a partner directly (already approved); they accept the agreement at first sign-in. */
    public function store(Request $request)
    {
        $data = $this->validated($request, null) + $request->validate([
            'user_email' => ['required', 'email', 'max:255', 'unique:partner_users,email'],
        ], ['user_email.unique' => 'Someone already signs in to the partner portal with this email.']);

        $password = Str::password(12, symbols: false);
        $partner = DB::transaction(function () use ($data, $password, $request) {
            $partner = Partner::create(collect($data)->except('user_email')->all() + [
                'number' => Partner::newNumber(), 'status' => 'active', 'approved_at' => now(), 'approved_by_staff_user_id' => $request->user('staff')->id,
            ]);
            $partner->ensureDefaultCode();
            PartnerUser::create([
                'partner_id' => $partner->id, 'name' => $data['contact_name'] ?: $data['name'], 'email' => $data['user_email'], 'phone' => $data['phone'] ?? null,
                'password' => $password, 'must_change_password' => true, 'is_active' => true,
            ]);

            return $partner;
        });
        $this->welcome($partner, $data['user_email'], $password);

        return redirect()->route('staff.partners.show', $partner)->with('success', "{$partner->name} added as partner {$partner->number}. Their sign-in details were emailed to {$data['user_email']}.");
    }

    public function show(Partner $partner)
    {
        $partner->load(['users', 'codes.promotion', 'defaultPromotion', 'approvedBy', 'commissionPlan']);
        $commissions = app(\App\Services\PartnerCommissions::class);
        $landlords = Landlord::with(['plan', 'partnerCode', 'promotion'])->where('partner_id', $partner->id)->latest('partner_attributed_at')->get();

        return view('staff.partners.show', [
            'partner' => $partner,
            'landlords' => $landlords,
            'promotions' => Promotion::orderBy('name')->get(),
            'codeUses' => Landlord::whereIn('partner_code_id', $partner->codes->pluck('id'))->selectRaw('partner_code_id, count(*) as n')->groupBy('partner_code_id')->pluck('n', 'partner_code_id'),
            'unlinked' => Landlord::whereNull('partner_id')->orderBy('name')->get(['id', 'name', 'contact_phone']),
            'balances' => $commissions->balances($partner),
            'plan' => $commissions->planFor($partner),
            'earnings' => $partner->commissions()->with('landlord', 'createdBy')->latest('earned_at')->latest('id')->limit(50)->get(),
            'earnedByLandlord' => $partner->commissions()->whereNotNull('landlord_id')->selectRaw('landlord_id, sum(net) as n')->groupBy('landlord_id')->pluck('n', 'landlord_id'),
        ]);
    }

    public function update(Request $request, Partner $partner)
    {
        $partner->update($this->validated($request, $partner));

        return back()->with('success', 'Partner details saved.');
    }

    public function setStatus(Request $request, Partner $partner)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'rejected'])],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        if ($data['status'] !== 'active' && blank($data['note'] ?? null)) {
            return back()->with('error', 'Give a reason; the partner is told.');
        }

        $approving = $data['status'] === 'active' && !$partner->approved_at;
        $partner->withAuditNote(($data['note'] ?? null) ?: 'Status: '.$data['status'])->update([
            'status' => $data['status'],
            'status_note' => $data['note'] ?? null,
        ] + ($approving ? ['approved_at' => now(), 'approved_by_staff_user_id' => $request->user('staff')->id] : []));
        if ($data['status'] === 'active') {
            $partner->ensureDefaultCode();
        }
        $this->tellPartner($partner, $data['status'], $data['note'] ?? null);

        return back()->with('success', match ($data['status']) {
            'active' => $approving ? "{$partner->name} approved. They've been emailed." : "{$partner->name} is active again.",
            'suspended' => "{$partner->name} suspended. They can't sign in, and their codes stop working.",
            default => "{$partner->name}'s application was turned down.",
        });
    }

    // --- Codes --------------------------------------------------------------------

    public function storeCode(Request $request, Partner $partner)
    {
        $request->merge(['code' => PartnerCode::normalize((string) $request->input('code'))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:30', 'regex:/^[A-Z0-9-]+$/', 'not_regex:/^\d+$/', 'unique:partner_codes,code'],
            'label' => ['nullable', 'string', 'max:120'],
            'promotion_id' => ['nullable', 'exists:promotions,id'],
        ], ['code.regex' => 'Use letters, numbers and dashes.', 'code.not_regex' => 'Numbers only are agent numbers; add a letter.', 'code.unique' => 'That code is taken.']);

        $partner->codes()->create($data + ['is_default' => !$partner->codes()->exists(), 'is_active' => true]);

        return back()->with('success', "Code {$data['code']} added.");
    }

    public function updateCode(Request $request, Partner $partner, PartnerCode $code)
    {
        abort_unless($code->partner_id === $partner->id, 404);
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'promotion_id' => ['nullable', 'exists:promotions,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $code->update(['label' => $data['label'] ?? null, 'promotion_id' => $data['promotion_id'] ?? null, 'is_active' => $code->is_default || $request->boolean('is_active')]);

        return back()->with('success', "Code {$code->code} saved.");
    }

    // --- Sign-ins -----------------------------------------------------------------

    public function storeUser(Request $request, Partner $partner)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:partner_users,email'],
        ], ['email.unique' => 'Someone already signs in to the partner portal with this email.']);

        $password = Str::password(12, symbols: false);
        $partner->users()->create($data + ['password' => $password, 'must_change_password' => true, 'is_active' => true]);
        $this->welcome($partner, $data['email'], $password);

        return back()->with('success', "{$data['name']} can now sign in. Their details were emailed to {$data['email']}.");
    }

    public function toggleUser(Partner $partner, PartnerUser $user)
    {
        abort_unless($user->partner_id === $partner->id, 404);
        $user->update(['is_active' => !$user->is_active]);

        return back()->with('success', $user->is_active ? "{$user->name} can sign in again." : "{$user->name} can no longer sign in.");
    }

    // --- Landlords ----------------------------------------------------------------

    /** Link a landlord to this partner (or move them here), with a reason. */
    public function assignLandlord(Request $request, Partner $partner, PartnerProgram $program)
    {
        $data = $request->validate([
            'landlord_id' => ['required', 'exists:landlords,id'],
            'code_id' => ['nullable', Rule::exists('partner_codes', 'id')->where('partner_id', $partner->id)],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ]);
        $landlord = Landlord::findOrFail($data['landlord_id']);
        $code = isset($data['code_id']) ? PartnerCode::find($data['code_id']) : $partner->ensureDefaultCode();

        $program->reassign($landlord, $code, $request->user('staff'), $data['reason']);

        return back()->with('success', "{$landlord->name} is now linked to {$partner->name}.");
    }

    public function unassignLandlord(Request $request, Partner $partner, Landlord $landlord, PartnerProgram $program)
    {
        abort_unless($landlord->partner_id === $partner->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);
        $program->reassign($landlord, null, $request->user('staff'), $data['reason']);

        return back()->with('success', "{$landlord->name} is no longer linked to {$partner->name}.");
    }

    // ------------------------------------------------------------------------------

    protected function validated(Request $request, ?Partner $partner): array
    {
        if ($request->filled('phone')) {
            $request->merge(['phone' => \App\Services\PhoneFormatter::format($request->phone)]);
        }

        return $request->validate([
            'type' => ['required', Rule::in(array_keys(Partner::TYPES))],
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', new PhoneNumber],
            'id_type' => ['nullable', Rule::in(['national_id', 'passport', 'company_registration'])],
            'id_number' => ['nullable', 'string', 'max:60'],
            'tin' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:300'],
            'default_promotion_id' => ['nullable', 'exists:promotions,id'],
            'commission_plan_id' => ['nullable', Rule::exists('commission_plans', 'id')->where('is_active', true)],
        ]);
    }

    protected function welcome(Partner $partner, string $email, string $password): void
    {
        $this->mail($email, new NoticeMail(
            subject: 'Your '.config('app.name').' partner portal sign-in',
            heading: 'Welcome to the '.config('app.name').' partner portal',
            lines: [
                "You can now sign in for {$partner->name} (agent number {$partner->number}).",
                "Email: {$email}",
                "Temporary password: {$password}",
                'You\'ll be asked to choose your own password when you first sign in.',
            ],
            actionText: 'Sign in',
            actionUrl: route('partner.login'),
        ));
    }

    protected function tellPartner(Partner $partner, string $status, ?string $note): void
    {
        $emails = $partner->users()->where('is_active', true)->pluck('email')->push($partner->email)->filter()->unique()->all();
        $mail = match ($status) {
            'active' => new NoticeMail(
                subject: 'You are now a '.config('app.name').' partner',
                heading: 'Your partner account is approved',
                lines: ["{$partner->name} is approved. Your agent number is {$partner->number}, and your code is ".($partner->defaultCode()?->code ?? 'in the portal').'.', 'Sign in to sign landlords up and share your code.'],
                actionText: 'Open the partner portal',
                actionUrl: route('partner.login'),
            ),
            'suspended' => new NoticeMail(
                subject: 'Your '.config('app.name').' partner account is suspended',
                heading: 'Partner account suspended',
                lines: ["{$partner->name}'s partner account has been suspended.", "Reason: {$note}", 'Contact Primora if you have questions.'],
            ),
            default => new NoticeMail(
                subject: 'Your '.config('app.name').' partner application',
                heading: 'About your partner application',
                lines: ["Thank you for applying. We're not able to approve {$partner->name} as a partner at this time.", "Reason: {$note}"],
            ),
        };
        $this->mail($emails, $mail);
    }

    protected function mail(string|array $to, NoticeMail $mail): void
    {
        try {
            if ($to) {
                Mail::to($to)->send($mail);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
