<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Models\Location;

class LocationController extends Controller
{
    public function index()
    {
        $locations = Location::withCount('estates')->latest()->paginate(15);

        return view('locations.index', compact('locations'));
    }

    public function store(StoreLocationRequest $request)
    {
        Location::create($request->validated());

        return redirect()->back()->with('success', 'Location created successfully.');
    }

    public function update(UpdateLocationRequest $request, Location $location)
    {
        $location->update($request->validated());

        return redirect()->back()->with('success', 'Location updated successfully.');
    }

    public function destroy(Location $location)
    {
        if ($location->estates()->exists()) {
            return redirect()->back()->with('error', 'Cannot delete a location with existing estates.');
        }

        $location->delete();

        return redirect()->back()->with('success', 'Location deleted successfully.');
    }
}
