<?php

namespace App\Services\TenantPortal;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What a signed-in tenant can see of their own account: invoices, balances,
 * payments. (The landlord scope is already set by AuthenticateTenant.)
 */
class TenantAccount
{
    protected ?array $leaseIds = null;

    public function __construct(public Tenant $tenant)
    {
    }

    public function leaseIds(): array
    {
        return $this->leaseIds ??= $this->tenant->leases()->pluck('id')->all();
    }

    /** All of the tenant's invoices (not cancelled), with `paid` = amount allocated so far. */
    public function invoices(): Builder
    {
        return Invoice::whereIn('lease_id', $this->leaseIds())
            ->where('status', '!=', 'cancelled')
            ->with('lease.unit.estate')
            ->withSum('allocations as paid', 'amount');
    }

    /** Unpaid / part-paid invoices, oldest due first, each with `balance`. */
    public function openInvoices(): Collection
    {
        return $this->invoices()->whereIn('status', ['unpaid', 'partial'])->orderBy('due_date')->get()
            ->each(fn (Invoice $i) => $i->balance = max(0, (float) $i->amount - (float) $i->paid))
            ->filter(fn (Invoice $i) => $i->balance > 0)
            ->values();
    }

    /** Renting more than one unit (so invoices need to say which unit they're for). */
    public function hasMultipleUnits(): bool
    {
        return \App\Models\Lease::whereIn('id', $this->leaseIds())->distinct()->count('unit_id') > 1;
    }

    public function findOpenInvoice(int $id): ?Invoice
    {
        return $this->openInvoices()->firstWhere('id', $id);
    }

    public function payments(): Builder
    {
        return Payment::where('payable_type', Tenant::class)->where('payable_id', $this->tenant->id)->where('status', '!=', 'cancelled');
    }

    /** Money paid but not yet put against an invoice (advance payments / overpayments). */
    public function credit(): float
    {
        return (float) $this->payments()->whereIn('status', ['unallocated', 'partially_allocated'])
            ->withSum('allocations as allocated', 'amount')->get()
            ->sum(fn (Payment $p) => max(0, (float) $p->amount - (float) $p->allocated));
    }

    public function ownsInvoice(Invoice $invoice): bool
    {
        return in_array($invoice->lease_id, $this->leaseIds(), true);
    }
}
