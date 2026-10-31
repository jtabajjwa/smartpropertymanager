<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Lease;
use Carbon\Carbon;

class InvoicingService
{
    /**
     * @return Invoice[]
     */
    public function createLeaseInvoices(Lease $lease): array
    {
        $invoices = [];

        if ($lease->security_deposit > 0) {
            $invoices[] = $this->createSecurityDepositInvoice($lease);
        }

        $invoices[] = $this->createRentInvoice($lease);

        return $invoices;
    }

    /**
     * The date $periods billing cycles after $startDate. Month-based cycles
     * never spill into the following month (31 Jan + 1 month = 28/29 Feb, not
     * 3 Mar) and, given the lease's original day of the month as $anchorDay,
     * return to it where the month is long enough (28 Feb + 1 month = 31 Mar).
     */
    public function calculateNextInvoiceDate(string $startDate, string $billingCycle, int $periods, ?int $anchorDay = null): Carbon
    {
        $start = Carbon::parse($startDate);

        $next = match ($billingCycle) {
            'daily' => $start->addDays($periods),
            'weekly' => $start->addWeeks($periods),
            'fortnightly' => $start->addWeeks($periods * 2),
            'quarterly' => $start->addMonthsNoOverflow($periods * 3),
            'yearly' => $start->addYearsNoOverflow($periods),
            default => $start->addMonthsNoOverflow($periods),
        };

        if ($anchorDay && in_array($billingCycle, ['monthly', 'quarterly', 'yearly'], true)) {
            $next->day(min($anchorDay, $next->daysInMonth));
        }

        return $next;
    }

    public function createRecurringInvoice(Lease $lease): Invoice
    {
        $amount = (string) $lease->rent_amount;
        $periodStart = Carbon::parse($lease->next_invoice_date);
        $anchorDay = $lease->start_date?->day;
        $periodEnd = $this->calculateNextInvoiceDate(
            $lease->next_invoice_date,
            $lease->billing_cycle,
            1,
            $anchorDay
        )->subDay();

        $invoice = Invoice::create([
            'invoiceable_type' => \App\Models\Lease::class,
            'invoiceable_id' => $lease->id,
            'lease_id' => $lease->id,
            'type' => 'rent',
            'title' => 'Rent — '.$periodStart->format('d M Y').' to '.$periodEnd->format('d M Y'),
            'invoice_date' => $lease->next_invoice_date,
            'amount' => $amount,
            'due_date' => $lease->next_invoice_date,
            'status' => 'unpaid',
            'notes' => 'Auto-generated recurring invoice.',
        ]);

        $invoice->update(['number' => app(NumberGenerator::class)->next($lease->landlord_id, 'invoice', 'INV')]);

        // Advance the lease's next_invoice_date by 1 period
        $nextDate = $this->calculateNextInvoiceDate(
            $lease->next_invoice_date,
            $lease->billing_cycle,
            1,
            $anchorDay
        );
        $lease->update(['next_invoice_date' => $nextDate]);

        return $invoice;
    }

    protected function createSecurityDepositInvoice(Lease $lease): Invoice
    {
        $invoice = Invoice::create([
            'invoiceable_type' => \App\Models\Lease::class,
            'invoiceable_id' => $lease->id,
            'lease_id' => $lease->id,
            'type' => 'security_deposit',
            'title' => 'Security Deposit',
            'invoice_date' => $lease->start_date,
            'amount' => $lease->security_deposit,
            'due_date' => $lease->start_date,
            'status' => 'unpaid',
            'notes' => 'Auto-generated on lease creation.',
        ]);

        $invoice->update(['number' => app(NumberGenerator::class)->next($lease->landlord_id, 'invoice', 'INV')]);

        return $invoice;
    }

    protected function createRentInvoice(Lease $lease): Invoice
    {
        $amount = bcmul((string) $lease->rent_amount, (string) $lease->required_upfront_periods, 2);
        $periods = $lease->required_upfront_periods;
        $perPeriod = number_format($lease->rent_amount, 0);
        $cycleName = match ($lease->billing_cycle) {
            'daily' => $periods === 1 ? 'day' : 'days',
            'weekly' => $periods === 1 ? 'week' : 'weeks',
            'fortnightly' => $periods === 1 ? 'fortnight' : 'fortnights',
            'monthly' => $periods === 1 ? 'month' : 'months',
            'quarterly' => $periods === 1 ? 'quarter' : 'quarters',
            'yearly' => $periods === 1 ? 'year' : 'years',
            default => $periods === 1 ? 'month' : 'months',
        };

        $invoice = Invoice::create([
            'invoiceable_type' => \App\Models\Lease::class,
            'invoiceable_id' => $lease->id,
            'lease_id' => $lease->id,
            'type' => 'rent',
            'title' => "{$periods} {$cycleName} rent (UGX {$perPeriod} each)",
            'invoice_date' => $lease->start_date,
            'amount' => $amount,
            'due_date' => $lease->start_date,
            'status' => 'unpaid',
            'notes' => 'Auto-generated on lease creation.',
        ]);

        $invoice->update(['number' => app(NumberGenerator::class)->next($lease->landlord_id, 'invoice', 'INV')]);

        return $invoice;
    }
}
