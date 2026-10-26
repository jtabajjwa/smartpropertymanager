<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Models\Landlord;
use App\Models\Location;

/**
 * Staff managing a landlord's locations on their behalf. Runs under the
 * staff.landlord-context middleware, so Location is scoped to $landlord.
 */
class LandlordLocationController extends Controller
{
    public function index(Landlord $landlord)
    {
        $locations = Location::withCount('estates')->orderBy('name')->paginate(15);

        return view('staff.landlords.locations', compact('landlord', 'locations'));
    }

    public function store(StoreLocationRequest $request, Landlord $landlord)
    {
        Location::create($request->validated());

        return redirect()->back()->with('success', 'Location created successfully.');
    }

    public function update(UpdateLocationRequest $request, Landlord $landlord, int $location)
    {
        Location::findOrFail($location)->update($request->validated());

        return redirect()->back()->with('success', 'Location updated successfully.');
    }

    public function destroy(Landlord $landlord, int $location)
    {
        $location = Location::findOrFail($location);

        if ($location->estates()->exists()) {
            return redirect()->back()->with('error', 'Cannot delete a location with existing estates.');
        }

        $location->delete();

        return redirect()->back()->with('success', 'Location deleted successfully.');
    }
}
