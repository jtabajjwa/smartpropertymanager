<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentAllocationRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SecurityDepositLedger;
use Illuminate\Support\Facades\DB;

class PaymentAllocationController extends Controller
{
    public function index()
    {
        // Eager load everything needed for the view
        $allocations = PaymentAllocation::with([
            'payment.payable',
            'invoice.invoiceable',
        ])->latest()->paginate(15);

        return view('payment-allocations.index', compact('allocations'));
    }

    public function store(StorePaymentAllocationRequest $request)
    {
        $amount = $request->amount;

        try {
            DB::transaction(function () use ($request, $amount) {
                // Lock both records to prevent concurrent over-allocation
                $payment = Payment::where('id', $request->payment_id)->lockForUpdate()->firstOrFail();
                $invoice = Invoice::with('invoiceable')->where('id', $request->invoice_id)->lockForUpdate()->firstOrFail();

                // Cross-payer check
                $paymentTenantId = $payment->payable_type === \App\Models\Tenant::class ? $payment->payable_id : null;
                $paymentCustomerId = $payment->payable_type === \App\Models\Customer::class ? $payment->payable_id : null;

                $invoiceTenantId = $invoice->invoiceable_type === \App\Models\Lease::class && $invoice->invoiceable ? $invoice->invoiceable->tenant_id : null;
                $invoiceCustomerId = $invoice->invoiceable_type === \App\Models\Customer::class ? $invoice->invoiceable_id : null;

                if ($paymentTenantId && $invoiceTenantId && $paymentTenantId !== $invoiceTenantId) {
                    throw new \Exception('Payment and invoice must belong to the same tenant.');
                }

                if ($paymentCustomerId && $invoiceCustomerId && $paymentCustomerId !== $invoiceCustomerId) {
                    throw new \Exception('Payment and invoice must belong to the same customer.');
                }

                if (($paymentTenantId && $invoiceCustomerId) || ($paymentCustomerId && $invoiceTenantId)) {
                    throw new \Exception('Cannot allocate a tenant payment to a customer invoice or vice-versa.');
                }

                // Invoice must be allocatable
                if (in_array($invoice->status, ['cancelled', 'paid'])) {
                    throw new \Exception('Cannot allocate to a '.$invoice->status.' invoice.');
                }

                // Check payment unallocated balance (query inside lock)
                $allocated = (string) $payment->allocations()->sum('amount');
                $paymentAvailable = bcsub((string) $payment->amount, $allocated, 2);
                if (bccomp((string) $amount, $paymentAvailable, 2) > 0) {
                    throw new \Exception('Amount exceeds payment unallocated balance (UGX '.number_format((float) $paymentAvailable).').');
                }

                // Check invoice unpaid balance (query inside lock)
                $invoicePaid = (string) $invoice->allocations()->sum('amount');
                $invoiceBalance = bcsub((string) $invoice->amount, $invoicePaid, 2);
                if (bccomp((string) $amount, $invoiceBalance, 2) > 0) {
                    throw new \Exception('Amount exceeds invoice unpaid balance (UGX '.number_format((float) $invoiceBalance).').');
                }

                $allocation = PaymentAllocation::create($request->validated());
                $allocation->invoice->syncStatus();
                $payment->updateAllocationStatus();
            });
        } catch (\Exception $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Payment allocated successfully.');
    }

    private function isProtectedSecurityDeposit(Invoice $invoice): bool
    {
        if ($invoice->type !== 'security_deposit') {
            return false;
        }

        return SecurityDepositLedger::where('lease_id', $invoice->lease_id)
            ->whereIn('type', ['refund', 'deduction'])
            ->exists();
    }
}
