<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeaseRequest;
use App\Http\Requests\UpdateLeaseRequest;
use App\Models\Lease;
use App\Models\LeaseTemplate;
use App\Services\CurrentLandlord;
use App\Services\InvoicingService;
use App\Services\NumberGenerator;
use App\Services\SmsNotificationService;
use App\Services\UnitStatusService;
use Barryvdh\DomPDF\Facade\Pdf;
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

        $leaseTemplates = LeaseTemplate::orderByDesc('is_default')->orderBy('name')->get();

        return view('leases.index', compact('leases', 'leaseTemplates'));
    }

    public function generateAgreement(Lease $lease)
    {
        $template = LeaseTemplate::findOrFail(request('template_id'));

        $pdf = Pdf::loadView('pdfs.lease-agreement', [
            'lease' => $lease->load(['tenant', 'unit.estate', 'landlord']),
            'content' => $template->renderFor($lease),
        ]);

        return $pdf->stream("agreement-{$lease->number}.pdf");
    }

    public function store(StoreLeaseRequest $request, \App\Actions\CreateLease $create)
    {
        $create($request->validated(), $request->file('agreement_file'));

        return redirect()->back()->with('success', 'Lease created successfully.');
    }

    public function update(UpdateLeaseRequest $request, Lease $lease, \App\Actions\UpdateLease $update)
    {
        try {
            $update($lease, $request->validated(), $request->file('agreement_file'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->back()->with('error', collect($e->errors())->flatten()->first());
        }

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
