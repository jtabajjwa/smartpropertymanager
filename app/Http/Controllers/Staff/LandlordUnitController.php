<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Models\Estate;
use App\Models\Landlord;
use App\Models\Unit;
use App\Services\PhotoUploader;
use App\Services\UnitStatusService;

/**
 * Staff managing a landlord's units on their behalf. Runs under the
 * staff.landlord-context middleware, so Unit/Estate are scoped to $landlord.
 * Status changes go through UnitStatusService, same as the landlord side.
 */
class LandlordUnitController extends Controller
{
    public function index(Landlord $landlord)
    {
        $query = Unit::with('estate')->withCount('leases');

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
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $units = $query->orderBy('estate_id')->orderBy('name')->paginate(20)->withQueryString();
        $estates = Estate::orderBy('name')->get();

        return view('staff.landlords.units', compact('landlord', 'units', 'estates'));
    }

    public function store(StoreUnitRequest $request, Landlord $landlord, PhotoUploader $photos)
    {
        app(\App\Services\UnitLimit::class)->ensureCanAdd($landlord, asStaff: true);

        $unit = Unit::create($request->safe()->except('photo'));
        $photos->sync($request, $unit, 'units/photos');

        return redirect()->back()->with('success', 'Unit created successfully.');
    }

    public function update(UpdateUnitRequest $request, Landlord $landlord, int $unit, PhotoUploader $photos)
    {
        $unit = Unit::findOrFail($unit);
        $data = $request->safe()->except(['photo', 'remove_photo']);

        if (isset($data['status']) && $data['status'] !== $unit->status) {
            app(UnitStatusService::class)->changeStatus($unit, $data['status']);
            unset($data['status']);
        }

        $unit->update($data);
        $photos->sync($request, $unit, 'units/photos');

        return redirect()->back()->with('success', 'Unit updated successfully.');
    }

    public function destroy(Landlord $landlord, int $unit)
    {
        $unit = Unit::findOrFail($unit);

        if ($unit->leases()->exists()) {
            return redirect()->back()->with('error', 'Cannot delete a unit with existing leases.');
        }

        $unit->delete();

        return redirect()->back()->with('success', 'Unit deleted successfully.');
    }
}
