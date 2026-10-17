<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Mail\PasswordResetCodeMail;
use App\Models\StaffPasswordResetCode;
use App\Models\StaffUser;
use Illuminate\Support\Facades\Mail;

class StaffPasswordResetController extends Controller
{
    public function showForgotForm()
    {
        return view('staff.auth.forgot-password');
    }

    public function sendCode(ForgotPasswordRequest $request)
    {
        $staffUser = StaffUser::where('email', $request->email)->first();

        if (!$staffUser || !$staffUser->isActive()) {
            return back()->with('success', 'If an account exists with that email address, a reset code has been sent.');
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

        Mail::to($staffUser->email)->send(new PasswordResetCodeMail($staffUser->name, $code));

        return redirect()->route('staff.reset-password', ['email' => $request->email])
            ->with('success', 'A reset code has been sent to your email address.');
    }

    public function showResetForm()
    {
        return view('staff.auth.reset-password');
    }

    public function reset(ResetPasswordRequest $request)
    {
        $staffUser = StaffUser::where('email', $request->email)->first();

        if (!$staffUser) {
            return back()->with('error', 'Invalid email address or code.');
        }

        $resetCode = StaffPasswordResetCode::where('staff_user_id', $staffUser->id)
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

        $staffUser->update([
            'password' => $request->password,
            'must_change_password' => false,
        ]);

        return redirect()->route('staff.login')
            ->with('success', 'Password has been reset. You can now log in.');
    }
}
