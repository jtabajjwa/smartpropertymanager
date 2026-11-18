<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use Illuminate\Http\Request;

/** Leases: list (e.g. active ones, to pick from when raising an invoice) and one lease. */
class LeaseController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['status' => ['nullable', 'in:active,terminated,evicted,all']]);
        $search = trim((string) $request->input('search'));
        $status = $request->input('status', 'active');

        $leases = Lease::with(['tenant', 'unit.estate'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->input('tenant_id'), fn ($q, $id) => $q->where('tenant_id', $id))
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('number', 'like', "%{$search}%")
                ->orWhereHas('tenant', fn ($t) => $t->where('name', 'like', "%{$search}%"))
                ->orWhereHas('unit', fn ($u) => $u->where('name', 'like', "%{$search}%"))))
            ->latest('start_date')->paginate(50);

        return response()->json($leases->through(fn (Lease $l) => LeaseResource::make($l)));
    }

    public function show(Lease $lease)
    {
        return response()->json(LeaseResource::make($lease->load(['tenant', 'unit.estate'])));
    }
}
