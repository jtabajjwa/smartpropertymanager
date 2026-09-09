<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\StaffChangePasswordRequest;
use Illuminate\Support\Facades\Auth;

class StaffChangePasswordController extends Controller
{
    public function show()
    {
        return view('staff.change-password');
    }

    public function update(StaffChangePasswordRequest $request)
    {
        Auth::guard('staff')->user()->update([
            'password' => $request->password,
            'must_change_password' => false,
        ]);

        return redirect()->route('staff.dashboard')->with('success', 'Password changed successfully.');
    }
}
