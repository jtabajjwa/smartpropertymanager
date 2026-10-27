<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\CurrentLandlord;

class UserController extends Controller
{
    public function index()
    {
        $query = User::where('landlord_id', CurrentLandlord::id())->latest();

        if (request('search')) {
            $search = request('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if (request('role')) {
            $query->where('role', request('role'));
        }

        if (request('status') !== null && request('status') !== '') {
            $query->where('is_active', request('status'));
        }

        $users = $query->paginate(15)->withQueryString();

        return view('users.index', compact('users'));
    }

    public function store(StoreUserRequest $request)
    {
        if (!\App\Support\PlanFeatures::allows('team') && User::exists()) {
            return redirect()->back()->with('error', 'Team members are not included in your plan. Upgrade your plan to add more people.');
        }

        User::create([
            'landlord_id' => CurrentLandlord::id(),
            'name' => $request->name,
            'email' => $request->email,
            'email_verified_at' => now(),
            'phone' => $request->phone,
            'password' => $request->password,
            'role' => $request->role,
            'is_active' => true,
            'must_change_password' => true,
        ]);

        return redirect()->back()->with('success', 'User created successfully.');
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        if ($user->isAdmin() && $request->role !== 'admin' && $this->isLastActiveAdmin($user)) {
            return redirect()->back()->with('error', 'Cannot change role. This is the only active admin in the system.');
        }

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'role' => $request->role,
        ]);

        return redirect()->back()->with('success', 'User updated successfully.');
    }

    public function toggleActive(User $user)
    {
        if ($user->is_active && $user->isAdmin() && $this->isLastActiveAdmin($user)) {
            return redirect()->back()->with('error', 'Cannot deactivate. This is the only active admin in the system.');
        }

        $user->update(['is_active' => !$user->is_active]);

        $status = $user->is_active ? 'activated' : 'deactivated';

        return redirect()->back()->with('success', "User {$status} successfully.");
    }

    private function isLastActiveAdmin(User $user): bool
    {
        return User::where('landlord_id', $user->landlord_id)
            ->where('role', 'admin')
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->doesntExist();
    }
}
