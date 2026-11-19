<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\Unit;
use App\Services\AccountBalances;
use App\Services\CurrentLandlord;
use App\Services\PhotoUploader;
use App\Services\UnitLimit;
use App\Services\UnitStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/** Units: list (e.g. vacant ones, to pick for a new lease), one unit with who's in it; add and edit. */
class UnitController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['status' => ['nullable', 'in:vacant,occupied,maintenance']]);
        $search = trim((string) $request->input('search'));

        $units = Unit::with('estate')
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('estate_id'), fn ($q, $id) => $q->where('estate_id', $id))
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', $search)
                ->orWhereHas('estate', fn ($e) => $e->where('name', 'like', "%{$search}%"))))
            ->orderBy('estate_id')->orderBy('name')->paginate(50);

        $data = self::withOccupants(collect($units->items()));

        return response()->json(['data' => $data->values()] + collect($units->toArray())->except('data')->all());
    }

    public function show(Unit $unit)
    {
        $unit->load('estate');
        $leases = Lease::with('tenant')->where('unit_id', $unit->id)->latest('start_date')->get();

        return response()->json(self::withOccupants(collect([$unit]))->first() + [
            'description' => $unit->description,
            'leases' => $leases->map(fn (Lease $l) => LeaseResource::make($l->setRelation('unit', $unit)))->values(),
        ]);
    }

    public function store(StoreUnitRequest $request, PhotoUploader $photos, UnitLimit $limit)
    {
        $limit->ensureCanAdd(Landlord::findOrFail(CurrentLandlord::id()));

        $unit = Unit::create($request->safe()->except('photo'));
        $photos->sync($request, $unit, 'units/photos');

        return response()->json(self::withOccupants(collect([$unit->load('estate')]))->first(), 201);
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

        return response()->json(self::withOccupants(collect([$unit->fresh('estate')]))->first());
    }

    /** Each unit with its current tenant and what they owe on that lease. */
    public static function withOccupants(Collection $units): Collection
    {
        $leases = Lease::with('tenant:id,name')->where('status', 'active')->whereIn('unit_id', $units->pluck('id'))
            ->orderByDesc('start_date')->get()->unique('unit_id')->keyBy('unit_id');
        $balances = app(AccountBalances::class)->forLeases($leases->pluck('id')->all());

        return $units->map(function (Unit $u) use ($leases, $balances) {
            $lease = $leases->get($u->id);

            return [
                'id' => $u->id,
                'name' => $u->name,
                'code' => $u->code,
                'status' => $u->status,
                'estate_id' => $u->estate_id,
                'estate' => $u->relationLoaded('estate') ? $u->estate?->name : null,
                'label' => trim(($u->relationLoaded('estate') && $u->estate ? $u->estate->name.' · ' : '').$u->name),
                'photo_url' => $u->photo ? Storage::disk('public')->url($u->photo) : null,
                'occupant' => $lease ? [
                    'tenant_id' => $lease->tenant_id, 'tenant' => $lease->tenant?->name, 'lease_id' => $lease->id, 'lease' => $lease->number,
                    'rent_amount' => (float) $lease->rent_amount, 'billing_cycle' => $lease->billing_cycle,
                    'owed' => round((float) ($balances[$lease->id]['owed'] ?? 0), 2), 'overdue' => round((float) ($balances[$lease->id]['overdue'] ?? 0), 2),
                ] : null,
            ];
        });
    }
}
