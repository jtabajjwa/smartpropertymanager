<?php

namespace App\Exports;

use App\Models\SecurityDepositLedger;
use Illuminate\Http\Request;

class SecurityDepositsExport
{
    public function __construct(protected Request $request) {}

    public function download(string $filename)
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Lease', 'Tenant', 'Estate', 'Unit', 'Date', 'Type', 'Amount', 'Notes']);

            $this->buildQuery()->with(['lease.tenant', 'lease.unit.estate'])->chunk(500, function ($entries) use ($handle) {
                foreach ($entries as $entry) {
                    fputcsv($handle, [
                        $entry->lease?->number ?? '',
                        $entry->lease?->tenant?->name ?? '',
                        $entry->lease?->unit?->estate?->name ?? '',
                        $entry->lease?->unit?->name ?? '',
                        $entry->date->format('d M Y'),
                        ucfirst($entry->type),
                        $entry->amount,
                        $entry->notes ?? '',
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
        $query = SecurityDepositLedger::query();

        if ($this->request->filled('lease_id')) {
            $query->where('lease_id', $this->request->lease_id);
        }

        if ($this->request->filled('tenant_id')) {
            $query->whereHas('lease', fn ($q) => $q->where('tenant_id', $this->request->tenant_id));
        }

        if ($this->request->filled('search')) {
            $search = $this->request->search;
            $query->whereHas('lease', function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                  ->orWhereHas('tenant', fn ($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        return $query->latest('date');
    }
}
