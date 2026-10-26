<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreLandlordRequest;
use App\Http\Requests\Staff\UpdateLandlordRequest;
use App\Models\Estate;
use App\Models\Landlord;
use App\Models\Plan;
use App\Models\User;
use App\Services\PropertyMetrics;
use App\Services\StandardLeaseTemplate;
use Illuminate\Support\Facades\DB;

class LandlordController extends Controller
{
    public function index()
    {
        $query = Landlord::withCount([
            'estates',
            'units',
            'units as occupied_units_count' => fn ($q) => $q->where('status', 'occupied'),
        ])->latest();

        if (request('search')) {
            $search = request('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('trading_name', 'like', "%{$search}%")
                  ->orWhere('contact_email', 'like', "%{$search}%")
                  ->orWhere('contact_phone', 'like', "%{$search}%");
            });
        }

        if (request('status')) {
            $query->where('status', request('status'));
        }

        $landlords = $query->paginate(15)->withQueryString();

        return view('staff.landlords.index', compact('landlords'));
    }

    /**
     * Property overview for one landlord. Runs inside the staff landlord
     * context, so Estate queries are scoped to this landlord.
     */
    public function show(Landlord $landlord, PropertyMetrics $metrics)
    {
        $summary = $metrics->summary($landlord->id);

        $estates = Estate::with('location')
            ->withCount([
                'units',
                'units as occupied_count' => fn ($q) => $q->where('status', 'occupied'),
                'units as vacant_count' => fn ($q) => $q->where('status', 'vacant'),
                'units as maintenance_count' => fn ($q) => $q->where('status', 'maintenance'),
            ])
            ->orderBy('name')
            ->get();

        // Which tier of the unit-based plan this portfolio size falls into.
        $plan = Plan::where('pricing_model', 'tiered')->where('is_active', true)->with('tiers')->first();
        $tier = $plan?->tierForUnitCount($summary['units']);

        return view('staff.landlords.show', compact('landlord', 'summary', 'estates', 'plan', 'tier'));
    }

    public function store(StoreLandlordRequest $request)
    {
        DB::transaction(function () use ($request) {
            $landlord = Landlord::create([
                'name' => $request->company_name,
                'trading_name' => $request->trading_name,
                'contact_email' => $request->email,
                'contact_phone' => $request->phone,
                'address' => $request->address,
                'status' => 'active',
                'subscription_status' => 'trialing',
            ]);

            app(StandardLeaseTemplate::class)->installFor($landlord->id);
            app(\App\Services\SmsCredits::class)->grantStarter($landlord);

            User::create([
                'landlord_id' => $landlord->id,
                'name' => $request->admin_name,
                'email' => $request->email,
                'email_verified_at' => now(),
                'phone' => $request->phone,
                'password' => $request->password,
                'role' => 'admin',
                'is_active' => true,
                'must_change_password' => true,
            ]);
        });

        return redirect()->back()->with('success', 'Landlord account created successfully.');
    }

    public function update(UpdateLandlordRequest $request, Landlord $landlord)
    {
        $landlord->update([
            'name' => $request->company_name,
            'trading_name' => $request->trading_name,
            'contact_email' => $request->email,
            'contact_phone' => $request->phone,
            'address' => $request->address,
        ]);

        return redirect()->back()->with('success', 'Landlord updated successfully.');
    }

    public function suspend(Landlord $landlord)
    {
        $landlord->update(['status' => 'suspended']);

        return redirect()->back()->with('success', 'Landlord suspended.');
    }

    public function reactivate(Landlord $landlord)
    {
        $landlord->update(['status' => 'active']);

        return redirect()->back()->with('success', 'Landlord reactivated.');
    }
}
