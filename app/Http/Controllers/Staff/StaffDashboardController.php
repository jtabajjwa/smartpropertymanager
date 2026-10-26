<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Landlord;
use App\Services\PropertyMetrics;

class StaffDashboardController extends Controller
{
    public function index(PropertyMetrics $metrics)
    {
        $totalLandlords = Landlord::count();
        $activeLandlords = Landlord::where('status', 'active')->count();
        $suspendedLandlords = Landlord::where('status', 'suspended')->count();
        $trialingLandlords = Landlord::where('subscription_status', 'trialing')->count();

        // Property portfolio across every landlord on the platform
        $portfolio = $metrics->summary();
        $topLandlords = $metrics->topLandlords(8);

        return view('staff.dashboard', compact(
            'totalLandlords',
            'activeLandlords',
            'suspendedLandlords',
            'trialingLandlords',
            'portfolio',
            'topLandlords',
        ));
    }
}
