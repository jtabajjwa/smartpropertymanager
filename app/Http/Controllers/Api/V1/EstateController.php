<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEstateRequest;
use App\Http\Requests\UpdateEstateRequest;
use App\Models\Estate;
use App\Models\Unit;
use App\Services\PhotoUploader;
use App\Support\Places;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Properties (estates): list with occupancy, one with its units; add and edit. */
class EstateController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('search'));

        $estates = Estate::withCount(['units', 'units as occupied_count' => fn ($q) => $q->where('status', 'occupied')])
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('district', 'like', "%{$search}%")->orWhere('address', 'like', "%{$search}%")))
            ->orderBy('name')->paginate(30);

        return response()->json($estates->through(fn (Estate $e) => self::summary($e)));
    }

    public function show(Estate $estate)
    {
        $estate->loadCount(['units', 'units as occupied_count' => fn ($q) => $q->where('status', 'occupied')]);
        $units = Unit::where('estate_id', $estate->id)->orderBy('name')->get();

        return response()->json(self::summary($estate) + [
            'description' => $estate->description,
            'latitude' => $estate->latitude, 'longitude' => $estate->longitude,
            'units' => UnitController::withOccupants($units)->values(),
        ]);
    }

    /** Countries and Ugandan districts for the form (Ugandan districts must come from the list). */
    public function places()
    {
        return response()->json(['countries' => Places::COUNTRIES, 'uganda_districts' => Places::UGANDA_DISTRICTS]);
    }

    public function store(StoreEstateRequest $request, PhotoUploader $photos)
    {
        $estate = Estate::create($request->safe()->except('photo'));
        $photos->sync($request, $estate, 'estates/photos');

        return response()->json(self::summary($estate->fresh()->loadCount('units')), 201);
    }

    public function update(UpdateEstateRequest $request, Estate $estate, PhotoUploader $photos)
    {
        $estate->update($request->safe()->except(['photo', 'remove_photo']));
        $photos->sync($request, $estate, 'estates/photos');

        return response()->json(self::summary($estate->fresh()->loadCount('units')));
    }

    public static function summary(Estate $e): array
    {
        return [
            'id' => $e->id,
            'name' => $e->name,
            'address' => $e->address,
            'district' => $e->district,
            'country' => $e->country,
            'unit_count' => (int) ($e->units_count ?? 0),
            'occupied_count' => (int) ($e->occupied_count ?? 0),
            'photo_url' => $e->photo ? Storage::disk('public')->url($e->photo) : null,
        ];
    }
}
