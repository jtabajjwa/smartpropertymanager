<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;

/**
 * What tenants owe, for a page of tenants or units at once (a few queries,
 * not a few per row). The same figures as the tenant's own account
 * (TenantPortal\TenantAccount):
 *
 *   owed     unpaid part of open invoices
 *   overdue  the part of that already past its due date
 *   credit   payments not yet put against an invoice (paid in advance)
 */
class AccountBalances
{
    public const EMPTY = ['owed' => 0.0, 'overdue' => 0.0, 'credit' => 0.0];

    /** @return array<int, array{owed: float, overdue: float, credit: float}> keyed by lease id (credit is the tenant's) */
    public function forLeases(array $leaseIds): array
    {
        $leaseIds = array_values(array_unique(array_filter($leaseIds)));
        if (!$leaseIds) {
            return [];
        }

        $balances = array_fill_keys($leaseIds, self::EMPTY);

        Invoice::whereIn('lease_id', $leaseIds)
            ->whereIn('status', ['unpaid', 'partial'])
            ->withSum('allocations as paid', 'amount')
            ->get()
            ->each(function (Invoice $invoice) use (&$balances) {
                $unpaid = max(0, (float) $invoice->amount - (float) $invoice->paid);
                $balances[$invoice->lease_id]['owed'] += $unpaid;
                if ($invoice->due_date->lt(today())) {
                    $balances[$invoice->lease_id]['overdue'] += $unpaid;
                }
            });

        $tenantOf = Lease::whereIn('id', $leaseIds)->pluck('tenant_id', 'id');
        $credits = $this->credits($tenantOf->unique()->values()->all());
        foreach ($tenantOf as $leaseId => $tenantId) {
            $balances[$leaseId]['credit'] = $credits[$tenantId] ?? 0.0;
        }

        return $balances;
    }

    /** @return array<int, array{owed: float, overdue: float, credit: float}> keyed by tenant id, over all their leases */
    public function forTenants(array $tenantIds): array
    {
        $tenantIds = array_values(array_unique(array_filter($tenantIds)));
        if (!$tenantIds) {
            return [];
        }

        $balances = array_fill_keys($tenantIds, self::EMPTY);
        $leases = Lease::whereIn('tenant_id', $tenantIds)->pluck('tenant_id', 'id');

        foreach ($this->forLeases($leases->keys()->all()) as $leaseId => $lease) {
            $balances[$leases[$leaseId]]['owed'] += $lease['owed'];
            $balances[$leases[$leaseId]]['overdue'] += $lease['overdue'];
        }
        foreach ($this->credits($tenantIds) as $tenantId => $credit) {
            $balances[$tenantId]['credit'] = $credit;
        }

        return $balances;
    }

    /** @return array<int, float> unallocated payment money by tenant id */
    protected function credits(array $tenantIds): array
    {
        $credits = [];
        Payment::where('payable_type', Tenant::class)->whereIn('payable_id', $tenantIds)
            ->whereIn('status', ['unallocated', 'partially_allocated'])
            ->withSum('allocations as allocated', 'amount')
            ->get()
            ->each(function (Payment $payment) use (&$credits) {
                $credits[$payment->payable_id] = ($credits[$payment->payable_id] ?? 0.0) + max(0, (float) $payment->amount - (float) $payment->allocated);
            });

        return $credits;
    }
}
