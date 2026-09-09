<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Models\Estate;
use App\Models\Unit;
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
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('estate', fn ($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        $units = $query->latest()->paginate(15)->withQueryString();

        return view('units.index', compact('units'));
    }

    public function store(StoreUnitRequest $request)
    {
        Unit::create($request->validated());

        return redirect()->back()->with('success', 'Unit created successfully.');
    }

    public function update(UpdateUnitRequest $request, Unit $unit)
    {
        $data = $request->validated();

        if (isset($data['status']) && $data['status'] !== $unit->status) {
            app(UnitStatusService::class)->changeStatus($unit, $data['status']);
            unset($data['status']);
        }

        $unit->update($data);

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
