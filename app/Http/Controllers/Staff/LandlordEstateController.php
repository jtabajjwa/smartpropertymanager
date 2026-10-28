<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEstateRequest;
use App\Http\Requests\UpdateEstateRequest;
use App\Models\Estate;
use App\Models\Landlord;
use App\Models\Location;
use App\Services\PhotoUploader;

/**
 * Staff managing a landlord's estates on their behalf. Runs under the
 * staff.landlord-context middleware, so Estate is scoped to $landlord.
 */
class LandlordEstateController extends Controller
{
    public function index(Landlord $landlord)
    {
        $query = Estate::query()
            ->withCount([
                'units',
                'units as occupied_count' => fn ($q) => $q->where('status', 'occupied'),
            ]);

        if (request('district')) {
            $query->where('district', request('district'));
        }

        if (request('search')) {
            $query->where('name', 'like', '%'.request('search').'%');
        }

        $estates = $query->orderBy('name')->paginate(15)->withQueryString();
        // Districts this landlord's estates are in, for the filter.
        $districts = Estate::whereNotNull('district')->distinct()->orderBy('district')->pluck('district');

        return view('staff.landlords.estates', compact('landlord', 'estates', 'districts'));
    }

    public function store(StoreEstateRequest $request, Landlord $landlord, PhotoUploader $photos)
    {
        $estate = Estate::create($request->safe()->except('photo'));
        $photos->sync($request, $estate, 'estates/photos');

        return redirect()->back()->with('success', 'Estate created successfully.');
    }

    public function update(UpdateEstateRequest $request, Landlord $landlord, int $estate, PhotoUploader $photos)
    {
        $estate = Estate::findOrFail($estate);
        $estate->update($request->safe()->except(['photo', 'remove_photo']));
        $photos->sync($request, $estate, 'estates/photos');

        return redirect()->back()->with('success', 'Estate updated successfully.');
    }

    public function destroy(Landlord $landlord, int $estate)
    {
        $estate = Estate::findOrFail($estate);

        if ($estate->units()->exists()) {
            return redirect()->back()->with('error', 'Cannot delete an estate with existing units.');
        }

        $estate->delete();

        return redirect()->back()->with('success', 'Estate deleted successfully.');
    }
}
