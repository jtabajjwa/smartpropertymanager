<?php

namespace App\Exports;

use App\Models\Lease;
use Illuminate\Http\Request;

class LeasesExport
{
    public function __construct(protected Request $request) {}

    public function download(string $filename)
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Number', 'Tenant', 'Unit', 'Estate', 'Status', 'Start Date', 'End Date', 'Rent Amount', 'Billing Cycle', 'Next Invoice Date']);

            $this->buildQuery()->with(['tenant', 'unit.estate'])->chunk(500, function ($leases) use ($handle) {
                foreach ($leases as $lease) {
                    fputcsv($handle, [
                        $lease->number ?? '',
                        $lease->tenant?->name ?? '',
                        $lease->unit?->name ?? '',
                        $lease->unit?->estate?->name ?? '',
                        ucfirst($lease->status),
                        $lease->start_date?->format('d M Y') ?? '',
                        $lease->end_date?->format('d M Y') ?? 'Ongoing',
                        $lease->rent_amount,
                        ucfirst($lease->billing_cycle),
                        $lease->next_invoice_date?->format('d M Y') ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    protected function buildQuery()
    {
        $query = Lease::query();

        if ($this->request->filled('status')) {
            $query->where('status', $this->request->status);
        }

        if ($this->request->filled('tenant_id')) {
            $query->where('tenant_id', $this->request->tenant_id);
        }

        if ($this->request->filled('estate_id')) {
            $query->whereHas('unit', function ($q) {
                $q->where('estate_id', $this->request->estate_id);
            });
        }

        if ($this->request->filled('search')) {
            $search = $this->request->search;
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('tenant', fn ($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        return $query->latest();
    }
}
