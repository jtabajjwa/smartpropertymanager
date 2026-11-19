<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateLease;
use App\Actions\UpdateLease;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeaseRequest;
use App\Http\Requests\UpdateLeaseRequest;
use App\Models\Invoice;
use App\Models\Lease;
use Illuminate\Http\Request;

/** Leases: list, one lease with its invoices; start one (with its first invoices); change or end one. */
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
        $lease->load(['tenant', 'unit.estate']);

        return response()->json(LeaseResource::make($lease) + [
            'has_invoices' => $lease->invoices()->exists(),
            'locked_fields' => $lease->invoices()->exists() ? UpdateLease::LOCKED_ONCE_INVOICED : [],
            'invoices' => Invoice::with('allocations')->where('lease_id', $lease->id)->latest('invoice_date')->latest('id')->limit(30)->get()
                ->map(fn (Invoice $i) => InvoiceController::summary($i)),
        ]);
    }

    public function store(StoreLeaseRequest $request, CreateLease $create)
    {
        [$lease, $invoices] = $create($request->validated(), $request->file('agreement_file'));

        return response()->json(LeaseResource::make($lease->fresh(['tenant', 'unit.estate'])) + ['invoices_created' => count($invoices)], 201);
    }

    /** POST (an agreement file may come as multipart). */
    public function update(UpdateLeaseRequest $request, Lease $lease, UpdateLease $update)
    {
        $update($lease, $request->validated(), $request->file('agreement_file'));

        return response()->json(LeaseResource::make($lease->fresh(['tenant', 'unit.estate'])));
    }
}
