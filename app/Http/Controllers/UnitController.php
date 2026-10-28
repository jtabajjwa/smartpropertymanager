<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Models\Estate;
use App\Models\Unit;
use App\Services\PhotoUploader;
use App\Services\UnitStatusService;

class UnitController extends Controller
{
    public function index()
    {
        $query = Unit::with('estate.location')->withCount('leases');

        if (request('status')) {
            $query->where('status', request('status'));
        }

        if (request('estate_id')) {
            $query->where('estate_id', request('estate_id'));
        }

        if (request('search')) {
            $search = request('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', $search)
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('estate', fn ($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        $units = $query->latest()->paginate(15)->withQueryString();

        // Subscription unit limit (null = none), shown above the list.
        $landlord = \App\Models\Landlord::find(\App\Services\CurrentLandlord::id());
        $unitLimit = $landlord?->max_units === null ? null : [
            'max' => $landlord->max_units,
            'used' => app(\App\Services\UnitLimit::class)->used($landlord),
        ];

        return view('units.index', compact('units', 'unitLimit'));
    }

    public function store(StoreUnitRequest $request, PhotoUploader $photos)
    {
        $landlord = \App\Models\Landlord::findOrFail(\App\Services\CurrentLandlord::id());
        app(\App\Services\UnitLimit::class)->ensureCanAdd($landlord, asStaff: false);

        $unit = Unit::create($request->safe()->except('photo'));
        $photos->sync($request, $unit, 'units/photos');

        return redirect()->back()->with('success', 'Unit created successfully.');
    }

    public function update(UpdateUnitRequest $request, Unit $unit, PhotoUploader $photos)
    {
        $data = $request->safe()->except(['photo', 'remove_photo']);

        if (isset($data['status']) && $data['status'] !== $unit->status) {
            app(UnitStatusService::class)->changeStatus($unit, $data['status']);
            unset($data['status']);
        }

        $unit->update($data);
        $photos->sync($request, $unit, 'units/photos');

        return redirect()->back()->with('success', 'Unit updated successfully.');
    }

    public function destroy(Unit $unit)
    {
        if ($unit->leases()->exists()) {
            return redirect()->back()->with('error', 'Cannot delete a unit with existing leases.');
        }

        $unit->delete();

        return redirect()->back()->with('success', 'Unit deleted successfully.');
    }
}
