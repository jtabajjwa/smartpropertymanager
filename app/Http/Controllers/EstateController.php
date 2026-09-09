<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEstateRequest;
use App\Http\Requests\UpdateEstateRequest;
use App\Models\Estate;
use App\Models\Location;

class EstateController extends Controller
{
    public function index()
    {
        $estates = Estate::with(['location', 'units'])
            ->withCount('units')
            ->latest()
            ->paginate(15);

        return view('estates.index', compact('estates'));
    }

    public function store(StoreEstateRequest $request)
    {
        Estate::create($request->validated());

        return redirect()->back()->with('success', 'Estate created successfully.');
    }

    public function update(UpdateEstateRequest $request, Estate $estate)
    {
        $estate->update($request->validated());

        return redirect()->back()->with('success', 'Estate updated successfully.');
    }

    public function destroy(Estate $estate)
    {
        if ($estate->units()->exists()) {
            return redirect()->back()->with('error', 'Cannot delete an estate with existing units.');
        }

        $estate->delete();

        return redirect()->back()->with('success', 'Estate deleted successfully.');
    }
}
