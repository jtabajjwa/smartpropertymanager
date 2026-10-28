<?php

namespace App\Exports;

use App\Models\Estate;
use Illuminate\Http\Request;

class EstatesExport
{
    public function __construct(protected Request $request) {}

    public function download(string $filename)
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            // Header Row
            fputcsv($handle, [
                'Name',
                'Address',
                'District',
                'Country',
                'Total Units',
                'Vacant Units',
                'Occupied Units',
                'Occupancy Rate (%)',
                'Total Active Expected Rent (UGX)',
                'Total Unpaid Arrears (UGX)'
            ]);

            // Data Rows
            $this->buildQuery()
                ->with(['units.leases.invoices' => function($q) {
                    $q->whereIn('status', ['unpaid', 'partial']);
                }])
                ->chunk(50, function ($estates) use ($handle) {
                    foreach ($estates as $estate) {
                        fputcsv($handle, [
                            $estate->name ?? '',
                            $estate->address ?? '',
                            $estate->district ?? '',
                            $estate->country ?? '',
                            $estate->units->count(),
                            $estate->vacantUnitsCount(),
                            $estate->occupiedUnitsCount(),
                            $estate->occupancyRate(),
                            $estate->totalActiveRent(),
                            $estate->totalUnpaidInvoices()
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
        $query = Estate::query();

        // Include any applicable filtering from estates index here...
        // e.g. district or search if available.

        return $query->latest();
    }
}
