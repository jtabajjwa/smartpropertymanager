<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Mail\NoticeMail;
use App\Mail\PasswordResetCodeMail;
use App\Models\Partner;
use App\Models\PartnerPasswordResetCode;
use App\Models\PartnerUser;
use App\Models\StaffUser;
use App\Rules\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Partner portal: apply, sign in and out, reset and change the password, accept the agreement. */
class PartnerAuthController extends Controller
{
    public function showLogin()
    {
        return Auth::guard('partner')->check() ? redirect()->route('partner.dashboard') : view('partner.auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        if (!Auth::guard('partner')->attempt($credentials, $request->boolean('remember'))) {
            return back()->withInput($request->only('email'))->with('error', 'Invalid email or password.');
        }
        $request->session()->regenerate();
        Auth::guard('partner')->user()->update(['last_login_at' => now()]);

        return redirect()->intended(route('partner.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::guard('partner')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('partner.login');
    }

    // --- Applying ---------------------------------------------------------------

    public function showApply()
    {
        return view('partner.auth.apply');
    }

    public function apply(Request $request)
    {
        if ($request->has('phone')) {
            $request->merge(['phone' => \App\Services\PhoneFormatter::format($request->phone)]);
        }
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Partner::TYPES))],
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc,filter', 'max:255', 'unique:partner_users,email'],
            'phone' => ['required', 'string', new PhoneNumber],
            'id_type' => ['required', Rule::in(['national_id', 'passport', 'company_registration'])],
            'id_number' => ['required', 'string', 'max:60'],
            'about' => ['nullable', 'string', 'max:1000'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'agreement' => ['accepted'],
        ], ['agreement.accepted' => 'Please accept the partner agreement.', 'email.unique' => 'There is already a partner account with this email. Sign in instead.']);

        $user = DB::transaction(function () use ($data) {
            $partner = Partner::create([
                'number' => Partner::newNumber(),
                'type' => $data['type'],
                'name' => $data['name'],
                'contact_name' => $data['contact_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'id_type' => $data['id_type'],
                'id_number' => $data['id_number'],
                'about' => $data['about'] ?? null,
                'status' => 'pending',
                'agreement_accepted_at' => now(),
            ]);

            return PartnerUser::create([
                'partner_id' => $partner->id, 'name' => $data['contact_name'], 'email' => $data['email'], 'phone' => $data['phone'],
                'password' => $data['password'], 'is_active' => true,
            ]);
        });

        $this->notifyStaff($user->partner);
        Auth::guard('partner')->login($user);
        $request->session()->regenerate();

        return redirect()->route('partner.dashboard')->with('success', 'Thank you for applying. Primora will review your application and let you know by email.');
    }

    protected function notifyStaff(Partner $partner): void
    {
        $emails = StaffUser::where('is_active', true)->get()->filter(fn (StaffUser $s) => $s->hasPermission('partners.manage'))->pluck('email')->filter()->all();
        try {
            if ($emails) {
                Mail::to($emails)->send(new NoticeMail(
                    subject: "Partner application: {$partner->name}",
                    heading: 'A new partner has applied',
                    lines: ["{$partner->name} ({$partner->typeLabel()}) applied to become a Primora partner.", "Contact: {$partner->contact_name}, {$partner->phone}, {$partner->email}."],
                    actionText: 'Review the application',
                    actionUrl: route('staff.partners.show', $partner),
                ));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // --- Agreement ----------------------------------------------------------------

    /** The agreement for people applying (signed-in partners see it with the accept button). */
    public function publicAgreement()
    {
        return Auth::guard('partner')->check() ? redirect()->route('partner.agreement') : view('partner.agreement', ['partner' => null]);
    }

    public function showAgreement()
    {
        return view('partner.agreement', ['partner' => Auth::guard('partner')->user()->partner]);
    }

    public function acceptAgreement(Request $request)
    {
        $request->validate(['agreement' => ['accepted']], ['agreement.accepted' => 'Tick the box to accept the agreement.']);
        $partner = Auth::guard('partner')->user()->partner;
        $partner->withAuditNote('Agreement accepted by '.Auth::guard('partner')->user()->name)->update(['agreement_accepted_at' => now()]);

        return redirect()->route('partner.dashboard')->with('success', 'Thank you. The partner agreement is accepted.');
    }

    // --- Password -----------------------------------------------------------------

    public function showPassword()
    {
        return view('partner.auth.password');
    }

    public function updatePassword(Request $request)
    {
        $user = Auth::guard('partner')->user();
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed', 'different:current_password'],
        ]);
        if (!Hash::check($request->current_password, $user->password)) {
            return back()->withErrors(['current_password' => 'That is not your current password.']);
        }
        $user->update(['password' => $request->password, 'must_change_password' => false]);

        return redirect()->route('partner.dashboard')->with('success', 'Password changed.');
    }

    public function showForgot()
    {
        return view('partner.auth.forgot-password');
    }

    public function sendCode(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        $user = PartnerUser::where('email', $request->email)->where('is_active', true)->first();

        if ($user) {
            PartnerPasswordResetCode::where('partner_user_id', $user->id)->where('used', false)->update(['used' => true]);
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            PartnerPasswordResetCode::create(['partner_user_id' => $user->id, 'code' => $code, 'expires_at' => now()->addMinutes(10)]);
            Mail::to($user->email)->send(new PasswordResetCodeMail($user->name, $code));
        }

        return redirect()->route('partner.reset-password', ['email' => $request->email])
            ->with('success', 'If a partner account uses that email, a reset code has been sent to it.');
    }

    public function showReset()
    {
        return view('partner.auth.reset-password');
    }

    public function reset(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);
        $user = PartnerUser::where('email', $request->email)->first();
        $reset = $user ? PartnerPasswordResetCode::where('partner_user_id', $user->id)->where('code', $request->code)
            ->where('used', false)->where('expires_at', '>', now())->latest()->first() : null;

        if (!$reset) {
            return back()->withInput($request->only('email'))->with('error', 'Invalid or expired reset code.');
        }
        $reset->update(['used' => true]);
        $user->update(['password' => $request->password, 'must_change_password' => false]);

        return redirect()->route('partner.login')->with('success', 'Password reset. You can sign in now.');
    }
}
