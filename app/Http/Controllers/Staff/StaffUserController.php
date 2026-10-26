<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreStaffUserRequest;
use App\Http\Requests\Staff\UpdateStaffUserRequest;
use App\Models\StaffUser;

class StaffUserController extends Controller
{
    public function index()
    {
        $query = StaffUser::latest();

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

        $staffUsers = $query->paginate(15)->withQueryString();

        return view('staff.staff-users.index', compact('staffUsers'));
    }

    public function store(StoreStaffUserRequest $request)
    {
        StaffUser::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => $request->password,
            'role' => $request->role,
            'permissions' => $this->permissionsFrom($request),
            'is_active' => true,
            'must_change_password' => true,
        ]);

        return redirect()->back()->with('success', 'Staff user created successfully.');
    }

    public function update(UpdateStaffUserRequest $request, StaffUser $staff_user)
    {
        // Nobody changes their own role or permissions (no self-escalation, no self-lockout).
        if ($staff_user->is($request->user('staff')) && ($request->role !== $staff_user->role || $this->permissionsFrom($request) !== $staff_user->effectivePermissions())) {
            return redirect()->back()->with('error', "You can't change your own role or permissions. Ask another staff admin.");
        }

        if ($staff_user->isAdmin() && $request->role !== 'admin' && $this->isLastActiveAdmin($staff_user)) {
            return redirect()->back()->with('error', 'Cannot change role. This is the only active admin in the system.');
        }

        $staff_user->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'role' => $request->role,
            'permissions' => $this->permissionsFrom($request),
        ]);

        return redirect()->back()->with('success', 'Staff user updated successfully.');
    }

    public function toggleActive(StaffUser $staff_user)
    {
        if ($staff_user->is_active && $staff_user->isAdmin() && $this->isLastActiveAdmin($staff_user)) {
            return redirect()->back()->with('error', 'Cannot deactivate. This is the only active admin in the system.');
        }

        $staff_user->update(['is_active' => !$staff_user->is_active]);

        $status = $staff_user->is_active ? 'activated' : 'deactivated';

        return redirect()->back()->with('success', "Staff user {$status} successfully.");
    }

    /** Admins have everything implicitly; others get the ticked permissions (plus what they imply). */
    private function permissionsFrom(\Illuminate\Http\Request $request): array
    {
        return $request->role === 'admin'
            ? \App\Support\StaffPermissions::all()
            : \App\Support\StaffPermissions::normalize((array) $request->input('permissions', []));
    }

    private function isLastActiveAdmin(StaffUser $staff_user): bool
    {
        return StaffUser::where('role', 'admin')
            ->where('is_active', true)
            ->where('id', '!=', $staff_user->id)
            ->doesntExist();
    }
}
