<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\StaffPasswordResetCode;
use App\Models\StaffUser;
use App\Services\SmsService;

class StaffPasswordResetController extends Controller
{
    public function showForgotForm()
    {
        return view('staff.auth.forgot-password');
    }

    public function sendCode(ForgotPasswordRequest $request, SmsService $sms)
    {
        $staffUser = StaffUser::where('phone', $request->phone)->first();

        if (!$staffUser || !$staffUser->isActive()) {
            return back()->with('success', 'If an account exists with that phone number, a reset code has been sent.');
        }

        StaffPasswordResetCode::where('staff_user_id', $staffUser->id)
            ->where('used', false)
            ->update(['used' => true]);

        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        StaffPasswordResetCode::create([
            'staff_user_id' => $staffUser->id,
            'code' => $code,
            'expires_at' => now()->addMinutes(10),
        ]);

        $appName = config('app.name');
        $sms->send($staffUser->phone, "Your {$appName} staff portal password reset code is: {$code}. Valid for 10 minutes.");

        return redirect()->route('staff.reset-password', ['phone' => $request->phone])
            ->with('success', 'A reset code has been sent to your phone.');
    }

    public function showResetForm()
    {
        return view('staff.auth.reset-password');
    }

    public function reset(ResetPasswordRequest $request)
    {
        $staffUser = StaffUser::where('phone', $request->phone)->first();

        if (!$staffUser) {
            return back()->with('error', 'Invalid phone number or code.');
        }

        $resetCode = StaffPasswordResetCode::where('staff_user_id', $staffUser->id)
            ->where('code', $request->code)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (!$resetCode) {
            return back()->withInput($request->only('phone'))
                ->with('error', 'Invalid or expired reset code.');
        }

        $resetCode->update(['used' => true]);

        $staffUser->update([
            'password' => $request->password,
            'must_change_password' => false,
        ]);

        return redirect()->route('staff.login')
            ->with('success', 'Password has been reset. You can now log in.');
    }
}
