<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StaffAuthController extends Controller
{
    public function showLogin()
    {
        return view('staff.auth.login');
    }

    public function login(LoginRequest $request)
    {
        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        if (!Auth::guard('staff')->attempt($credentials, $remember)) {
            return back()->withInput($request->only('email'))
                ->with('error', 'Invalid email or password.');
        }

        $staffUser = Auth::guard('staff')->user();

        if (!$staffUser->isActive()) {
            Auth::guard('staff')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withInput($request->only('email'))
                ->with('error', 'Your account has been deactivated. Contact an administrator.');
        }

        $request->session()->regenerate();

        if ($staffUser->must_change_password) {
            return redirect()->route('staff.change-password');
        }

        return redirect()->intended(route('staff.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::guard('staff')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('staff.login');
    }
}
