<?php

namespace App\Exports;

use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentsExport
{
    public function __construct(protected Request $request) {}

    public function download(string $filename)
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Number', 'Type', 'Payer', 'Amount', 'Date', 'Method', 'Reference', 'Status', 'Unallocated']);

            $this->buildQuery()->with(['payable', 'allocations'])->chunk(500, function ($payments) use ($handle) {
                foreach ($payments as $payment) {
                    $method = match ($payment->payment_method) {
                        'cash' => 'Cash',
                        'bank_transfer' => 'Bank Transfer',
                        'mtn_mobile_money' => 'MTN Mobile Money',
                        'airtel_mobile_money' => 'Airtel Money',
                        'flexipay' => 'FlexiPay',
                        default => $payment->payment_method,
                    };

                    fputcsv($handle, [
                        $payment->number ?? '',
                        $payment->payable_type === \App\Models\Customer::class ? 'Customer' : 'Tenant',
                        $payment->display_payer_name,
                        $payment->amount,
                        $payment->payment_date->format('d M Y'),
                        $method,
                        $payment->reference ?? '',
                        ucfirst($payment->status),
                        $payment->unallocatedAmount(),
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
        $query = Payment::query();

        if ($this->request->filled('status')) {
            $query->where('status', $this->request->status);
        }

        if ($this->request->filled('payment_method')) {
            $query->where('payment_method', $this->request->payment_method);
        }

        if ($this->request->filled('tenant_id')) {
            $query->where('payable_type', \App\Models\Tenant::class)
                  ->where('payable_id', $this->request->tenant_id);
        }

        if ($this->request->filled('customer_id')) {
            $query->where('payable_type', \App\Models\Customer::class)
                  ->where('payable_id', $this->request->customer_id);
        }

        if ($this->request->filled('date_from')) {
            $query->whereDate('payment_date', '>=', $this->request->date_from);
        }

        if ($this->request->filled('date_to')) {
            $query->whereDate('payment_date', '<=', $this->request->date_to);
        }

        if ($this->request->filled('search')) {
            $search = $this->request->search;
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                  ->orWhere('reference', 'like', "%{$search}%")
                  ->orWhereHasMorph('payable', [\App\Models\Tenant::class, \App\Models\Customer::class], fn ($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        return $query->latest();
    }
}
