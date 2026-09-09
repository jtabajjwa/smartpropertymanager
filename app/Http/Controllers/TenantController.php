<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Models\Tenant;
use App\Services\CurrentLandlord;
use App\Services\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TenantController extends Controller
{
    public function index()
    {
        $query = Tenant::with('leases')->withCount(['leases', 'payments']);

        if (request('type')) {
            $query->where('type', request('type'));
        }

        if (request('search')) {
            $search = request('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('number', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('primary_phone', 'like', "%{$search}%");
            });
        }

        $tenants = $query->latest()->paginate(15)->withQueryString();

        return view('tenants.index', compact('tenants'));
    }

    public function store(StoreTenantRequest $request)
    {
        $data = $request->validated();

        if ($request->hasFile('photo')) {
            $data['photo'] = $request->file('photo')->store('tenants/photos', 'public');
        }
        if ($request->hasFile('id_scan')) {
            $data['id_scan'] = $request->file('id_scan')->store('tenants/id-scans', 'public');
        }
        if ($request->hasFile('lc_letter')) {
            $data['lc_letter'] = $request->file('lc_letter')->store('tenants/lc-letters', 'public');
        }

        DB::transaction(function () use ($data) {
            $tenant = Tenant::create($data);
            $tenant->update(['number' => app(NumberGenerator::class)->next(CurrentLandlord::id(), 'tenant', 'TEN')]);
        });

        return redirect()->back()->with('success', 'Tenant created successfully.');
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant)
    {
        $data = $request->validated();

        if ($request->hasFile('photo')) {
            if ($tenant->photo) Storage::disk('public')->delete($tenant->photo);
            $data['photo'] = $request->file('photo')->store('tenants/photos', 'public');
        }
        if ($request->hasFile('id_scan')) {
            if ($tenant->id_scan) Storage::disk('public')->delete($tenant->id_scan);
            $data['id_scan'] = $request->file('id_scan')->store('tenants/id-scans', 'public');
        }
        if ($request->hasFile('lc_letter')) {
            if ($tenant->lc_letter) Storage::disk('public')->delete($tenant->lc_letter);
            $data['lc_letter'] = $request->file('lc_letter')->store('tenants/lc-letters', 'public');
        }

        $tenant->update($data);

        return redirect()->back()->with('success', 'Tenant updated successfully.');
    }

    public function destroy(Tenant $tenant)
    {
        if ($tenant->leases()->exists()) {
            return redirect()->back()->with('error', 'Cannot delete a tenant with existing leases.');
        }

        if ($tenant->payments()->exists()) {
            return redirect()->back()->with('error', 'Cannot delete a tenant with existing payments.');
        }

        if ($tenant->photo) Storage::disk('public')->delete($tenant->photo);
        if ($tenant->id_scan) Storage::disk('public')->delete($tenant->id_scan);
        if ($tenant->lc_letter) Storage::disk('public')->delete($tenant->lc_letter);

        $tenant->delete();

        return redirect()->back()->with('success', 'Tenant deleted successfully.');
    }
}
