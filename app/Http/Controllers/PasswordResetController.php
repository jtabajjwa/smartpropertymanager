<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Services\SmsService;

class PasswordResetController extends Controller
{
    public function showForgotForm()
    {
        return view('auth.forgot-password');
    }

    public function sendCode(ForgotPasswordRequest $request, SmsService $sms)
    {
        $user = User::where('phone', $request->phone)->first();

        if (!$user || !$user->isActive()) {
            return back()->with('success', 'If an account exists with that phone number, a reset code has been sent.');
        }

        // Invalidate previous unused codes
        PasswordResetCode::where('user_id', $user->id)
            ->where('used', false)
            ->update(['used' => true]);

        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        PasswordResetCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'expires_at' => now()->addMinutes(10),
        ]);

        $appName = config('app.name');
        $sms->send($user->phone, "Your {$appName} password reset code is: {$code}. Valid for 10 minutes.");

        return redirect()->route('reset-password', ['phone' => $request->phone])
            ->with('success', 'A reset code has been sent to your phone.');
    }

    public function showResetForm()
    {
        return view('auth.reset-password');
    }

    public function reset(ResetPasswordRequest $request)
    {
        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            return back()->with('error', 'Invalid phone number or code.');
        }

        $resetCode = PasswordResetCode::where('user_id', $user->id)
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

        $user->update([
            'password' => $request->password,
            'must_change_password' => false,
        ]);

        return redirect()->route('login')
            ->with('success', 'Password has been reset. You can now log in.');
    }
}
