<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSignupRequest;
use App\Models\Landlord;
use App\Models\User;
use App\Services\StandardLeaseTemplate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SignupController extends Controller
{
    public const REF_COOKIE = 'primora_ref';

    /** The sign-up form; ?ref=CODE (a partner's link) is remembered for 30 days. */
    public function show(\Illuminate\Http\Request $request, \App\Services\PartnerProgram $program)
    {
        $ref = $request->query('ref');
        if (is_string($ref) && ($code = $program->resolve($ref))) {
            \Illuminate\Support\Facades\Cookie::queue(self::REF_COOKIE, $code->code, 60 * 24 * 30);
        } else {
            $code = $program->resolve((string) $request->cookie(self::REF_COOKIE));
        }

        return view('auth.signup', ['refCode' => $code, 'refPromotion' => $code?->effectivePromotion()?->isRunning() ? $code->effectivePromotion() : null]);
    }

    public function store(StoreSignupRequest $request)
    {
        $user = DB::transaction(function () use ($request) {
            $landlord = Landlord::create([
                'name' => $request->company_name,
                'contact_email' => $request->email,
                'contact_phone' => $request->phone,
                'status' => 'active',
                'subscription_status' => 'trialing',
            ]);

            app(StandardLeaseTemplate::class)->installFor($landlord->id);
            app(\App\Services\SmsCredits::class)->grantStarter($landlord);

            $user = User::create([
                'landlord_id' => $landlord->id,
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => $request->password,
                'role' => 'admin',
                'is_active' => true,
                'must_change_password' => false,
            ]);

            $landlord->acceptTerms($user, $request->ip());

            // A partner's code (typed, or from their link): the landlord is theirs, with any promotion.
            $program = app(\App\Services\PartnerProgram::class);
            if ($request->filled('partner_code') && ($code = $program->resolve($request->partner_code))) {
                $program->attach($landlord, $code, 'code');
            }

            return $user;
        });
        \Illuminate\Support\Facades\Cookie::queue(\Illuminate\Support\Facades\Cookie::forget(self::REF_COOKIE));

        Auth::login($user);
        $user->sendEmailVerificationNotification();

        return redirect()->route('verification.notice');
    }
}
