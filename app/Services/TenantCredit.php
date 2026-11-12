<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * A tenant's credit: money they paid that isn't on any invoice yet (e.g. they
 * paid more than the invoice they were paying). apply() puts it on their open
 * invoices automatically: the oldest payment first, onto the oldest invoice
 * first. It runs when a tenant's payment comes in and whenever a new invoice is
 * raised for them, so overpaid rent pays the next invoice.
 */
class TenantCredit
{
    /**
     * Apply the tenant's unused payments to their unpaid invoices.
     *
     * @return float the amount applied
     */
    public function apply(int $tenantId): float
    {
        return DB::transaction(function () use ($tenantId) {
            $payments = Payment::withoutGlobalScopes()
                ->where('payable_type', Tenant::class)->where('payable_id', $tenantId)
                ->whereIn('status', ['unallocated', 'partially_allocated'])
                ->orderBy('payment_date')->orderBy('id')
                ->lockForUpdate()->get();

            if ($payments->isEmpty()) {
                return 0.0;
            }

            $invoices = Invoice::withoutGlobalScopes()
                ->where('invoiceable_type', Lease::class)
                ->whereIn('lease_id', Lease::withoutGlobalScopes()->where('tenant_id', $tenantId)->select('id'))
                ->whereIn('status', ['unpaid', 'partial'])
                ->orderBy('due_date')->orderBy('id')
                ->lockForUpdate()->get();

            $applied = '0';
            $touchedPayments = [];

            foreach ($invoices as $invoice) {
                $due = bcsub((string) $invoice->amount, (string) $invoice->allocations()->sum('amount'), 2);
                $touched = false;

                foreach ($payments as $payment) {
                    if (bccomp($due, '0', 2) <= 0) {
                        break;
                    }
                    $free = bcsub((string) $payment->amount, (string) $payment->allocations()->sum('amount'), 2);
                    if (bccomp($free, '0', 2) <= 0) {
                        continue;
                    }

                    $amount = bccomp($free, $due, 2) < 0 ? $free : $due;
                    // landlord_id from the payment: this also runs outside a landlord request (gateway callbacks).
                    (new PaymentAllocation(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => $amount]))
                        ->forceFill(['landlord_id' => $payment->landlord_id])
                        ->withAuditNote('Applied automatically from the tenant\'s credit (money paid in advance).')->save();
                    $touched = true;
                    $due = bcsub($due, $amount, 2);
                    $applied = bcadd($applied, $amount, 2);
                    $touchedPayments[$payment->id] = $payment;
                }

                if ($touched) {
                    $invoice->syncStatus();
                }
            }

            foreach ($touchedPayments as $payment) {
                $payment->updateAllocationStatus();
            }

            return (float) $applied;
        });
    }
}
