<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEstateRequest;
use App\Http\Requests\UpdateEstateRequest;
use App\Models\Estate;
use App\Models\Location;
use App\Services\PhotoUploader;

class EstateController extends Controller
{
    public function index()
    {
        $estates = Estate::with(['units'])
            ->withCount('units')
            ->latest()
            ->paginate(15);

        return view('estates.index', compact('estates'));
    }

    public function store(StoreEstateRequest $request, PhotoUploader $photos)
    {
        $estate = Estate::create($request->safe()->except('photo'));
        $photos->sync($request, $estate, 'estates/photos');

        return redirect()->back()->with('success', 'Estate created successfully.');
    }

    public function update(UpdateEstateRequest $request, Estate $estate, PhotoUploader $photos)
    {
        $estate->update($request->safe()->except(['photo', 'remove_photo']));
        $photos->sync($request, $estate, 'estates/photos');

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
