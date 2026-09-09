<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeaseRequest;
use App\Http\Requests\UpdateLeaseRequest;
use App\Models\Lease;
use App\Services\CurrentLandlord;
use App\Services\InvoicingService;
use App\Services\NumberGenerator;
use App\Services\SmsNotificationService;
use App\Services\UnitStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LeaseController extends Controller
{
    public function export()
    {
        return app(\App\Exports\LeasesExport::class)->download('leases-'.now()->format('Y-m-d').'.csv');
    }

    public function index()
    {
        $query = Lease::with(['tenant', 'unit.estate'])->withCount('invoices');

        if (request('status')) {
            $query->where('status', request('status'));
        }

        if (request('tenant_id')) {
            $query->where('tenant_id', request('tenant_id'));
        }

        if (request('estate_id')) {
            $query->whereHas('unit', function ($q) {
                $q->where('estate_id', request('estate_id'));
            });
        }

        if (request('search')) {
            $search = request('search');
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('tenant', fn ($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        $leases = $query->latest()->paginate(15)->withQueryString();

        return view('leases.index', compact('leases'));
    }

    public function store(StoreLeaseRequest $request)
    {
        $data = $request->validated();
        $data['status'] = $data['status'] ?? 'active';

        if ($data['security_deposit'] == null) {
            $data['security_deposit'] = 0;
        }

        if ($request->hasFile('agreement_file')) {
            $data['agreement_file'] = $request->file('agreement_file')->store('leases/agreements', 'public');
        }

        $invoicingService = app(InvoicingService::class);
        $data['next_invoice_date'] = $invoicingService->calculateNextInvoiceDate(
            $data['start_date'],
            $data['billing_cycle'],
            $data['required_upfront_periods']
        );

        $invoices = DB::transaction(function () use ($data, $invoicingService) {
            $lease = Lease::create($data);
            $lease->update(['number' => app(NumberGenerator::class)->next(CurrentLandlord::id(), 'lease', 'LSE')]);

            app(UnitStatusService::class)->onLeaseCreated($lease);

            return $invoicingService->createLeaseInvoices($lease);
        });

        $sms = app(SmsNotificationService::class);
        foreach ($invoices as $invoice) {
            // $sms->invoiceCreated($invoice->load('lease.tenant'));
        }

        return redirect()->back()->with('success', 'Lease created successfully.');
    }

    public function update(UpdateLeaseRequest $request, Lease $lease)
    {
        if (in_array($lease->status, ['terminated', 'evicted'])) {
            return redirect()->back()->with('error', 'Cannot edit a terminated or evicted lease.');
        }

        $data = $request->validated();
        $oldUnitId = $lease->unit_id;
        $oldStatus = $lease->status;

        // Lock financial fields once invoices exist
        if ($lease->invoices()->exists()) {
            unset($data['rent_amount'], $data['security_deposit'], $data['billing_cycle'], $data['required_upfront_periods'], $data['tenant_id'], $data['unit_id'], $data['start_date']);
        }

        if ($request->hasFile('agreement_file')) {
            if ($lease->agreement_file) {
                Storage::disk('public')->delete($lease->agreement_file);
            }
            $data['agreement_file'] = $request->file('agreement_file')->store('leases/agreements', 'public');
        }

        DB::transaction(function () use ($data, $lease, $oldUnitId, $oldStatus) {
            $lease->update($data);
            app(UnitStatusService::class)->onLeaseUpdated($lease, $oldUnitId, $oldStatus);
        });

        return redirect()->back()->with('success', 'Lease updated successfully.');
    }

    public function destroy(Lease $lease)
    {
        if ($lease->invoices()->exists()) {
            return redirect()->back()->with('error', 'Cannot delete a lease with existing invoices.');
        }

        DB::transaction(function () use ($lease) {
            app(UnitStatusService::class)->onLeaseDeleted($lease);
            $lease->delete();
        });

        if ($lease->agreement_file) {
            Storage::disk('public')->delete($lease->agreement_file);
        }

        return redirect()->back()->with('success', 'Lease deleted successfully.');
    }
}
