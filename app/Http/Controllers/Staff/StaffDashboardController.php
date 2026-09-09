<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Landlord;

class StaffDashboardController extends Controller
{
    public function index()
    {
        $totalLandlords = Landlord::count();
        $activeLandlords = Landlord::where('status', 'active')->count();
        $suspendedLandlords = Landlord::where('status', 'suspended')->count();
        $trialingLandlords = Landlord::where('subscription_status', 'trialing')->count();

        return view('staff.dashboard', compact(
            'totalLandlords',
            'activeLandlords',
            'suspendedLandlords',
            'trialingLandlords',
        ));
    }
}
