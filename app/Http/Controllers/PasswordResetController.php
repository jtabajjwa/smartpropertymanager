<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Mail\PasswordResetCodeMail;
use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class PasswordResetController extends Controller
{
    public function showForgotForm()
    {
        return view('auth.forgot-password');
    }

    public function sendCode(ForgotPasswordRequest $request)
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !$user->isActive()) {
            return back()->with('success', 'If an account exists with that email address, a reset code has been sent.');
        }

        PasswordResetCode::where('user_id', $user->id)
            ->where('used', false)
            ->update(['used' => true]);

        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        PasswordResetCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'expires_at' => now()->addMinutes(10),
        ]);

        Mail::to($user->email)->send(new PasswordResetCodeMail($user->name, $code));

        return redirect()->route('reset-password', ['email' => $request->email])
            ->with('success', 'A reset code has been sent to your email address.');
    }

    public function showResetForm()
    {
        return view('auth.reset-password');
    }

    public function reset(ResetPasswordRequest $request)
    {
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return back()->with('error', 'Invalid email address or code.');
        }

        $resetCode = PasswordResetCode::where('user_id', $user->id)
            ->where('code', $request->code)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (!$resetCode) {
            return back()->withInput($request->only('email'))
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
