<?php

namespace App\Exports;

use App\Models\Invoice;
use Illuminate\Http\Request;

class InvoicesExport
{
    public function __construct(protected Request $request) {}

    public function download(string $filename)
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Number', 'Type', 'Payer', 'Lease', 'Title', 'Amount', 'Balance', 'Due Date', 'Status']);

            $this->buildQuery()->with(['invoiceable', 'lease.tenant', 'allocations'])->chunk(500, function ($invoices) use ($handle) {
                foreach ($invoices as $invoice) {
                    $type = match ($invoice->type) {
                        'rent' => 'Rent',
                        'security_deposit' => 'Security Deposit',
                        'utility' => 'Utility',
                        default => $invoice->type,
                    };

                    fputcsv($handle, [
                        $invoice->number ?? '',
                        $type,
                        $invoice->display_payer_name,
                        $invoice->lease?->number ?? '',
                        $invoice->title,
                        $invoice->amount,
                        $invoice->unpaidBalance(),
                        $invoice->due_date->format('d M Y'),
                        ucfirst($invoice->status),
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
        $query = Invoice::query();

        if ($this->request->filled('status')) {
            if ($this->request->status === 'unpaid') {
                $query->whereIn('status', ['unpaid', 'partial']);
            } else {
                $query->where('status', $this->request->status);
            }
        }

        if ($this->request->filled('tenant_id')) {
            $query->whereIn('invoiceable_type', [\App\Models\Lease::class])
                  ->whereHas('lease', fn ($q) => $q->where('tenant_id', $this->request->tenant_id));
        }

        if ($this->request->filled('customer_id')) {
            $query->where('invoiceable_type', \App\Models\Customer::class)
                  ->where('invoiceable_id', $this->request->customer_id);
        }

        if ($this->request->filled('estate_id')) {
            $query->whereHas('lease.unit', fn ($q) => $q->where('estate_id', $this->request->estate_id));
        }

        if ($this->request->filled('lease_id')) {
            $query->where('lease_id', $this->request->lease_id);
        }

        if ($this->request->filled('type')) {
            $query->where('type', $this->request->type);
        }

        if ($this->request->filled('due_from')) {
            $query->whereDate('due_date', '>=', $this->request->due_from);
        }

        if ($this->request->filled('due_to')) {
            $query->whereDate('due_date', '<=', $this->request->due_to);
        }

        if ($this->request->filled('search')) {
            $search = $this->request->search;
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhereHasMorph('invoiceable', [\App\Models\Lease::class, \App\Models\Customer::class], function($q, $type) use ($search) {
                      if ($type === \App\Models\Lease::class) {
                          $q->whereHas('tenant', fn ($t) => $t->where('name', 'like', "%{$search}%"));
                      } else {
                          $q->where('name', 'like', "%{$search}%");
                      }
                  });
            });
        }

        return $query->latest();
    }
}
