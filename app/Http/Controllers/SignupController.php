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
    public function show()
    {
        return view('auth.signup');
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

            return User::create([
                'landlord_id' => $landlord->id,
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => $request->password,
                'role' => 'admin',
                'is_active' => true,
                'must_change_password' => false,
            ]);
        });

        Auth::login($user);
        $user->sendEmailVerificationNotification();

        return redirect()->route('verification.notice');
    }
}
