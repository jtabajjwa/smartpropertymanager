<?php

namespace App\Accounting;

use App\Accounting\Reports\Report;
use App\Models\Estate;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\LandlordTax;
use App\Models\Liquidation;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\SecurityDepositLedger;
use App\Models\TaxPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A landlord's books, built from what they already record (invoices,
 * payments, expenses, deposits) plus Primora's charges. No extra data entry.
 *
 *  - Cash basis: income when money is received, by what it paid for;
 *    payments not yet matched to an invoice count as rent received in advance.
 *  - Accrual basis: income when invoiced; written-off invoices become bad debts.
 *  - Security deposits are held for tenants, never income; deductions and
 *    forfeits are income when made.
 *
 * Runs in the landlord's scope (CurrentLandlord).
 */
class LandlordBooks
{
    public const UNASSIGNED = 0;

    public function __construct(protected Landlord $landlord)
    {
    }

    public static function for(Landlord $landlord): self
    {
        return new self($landlord);
    }

    // --- Fiscal years ---------------------------------------------------------

    /** The fiscal year containing $date, named by the year it starts in. */
    public function fiscalYearOf(?CarbonImmutable $date = null): int
    {
        $date ??= CarbonImmutable::now();
        $start = $this->landlord->fiscal_year_start_month ?: 7;

        return $date->month >= $start ? $date->year : $date->year - 1;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function fiscalYearRange(int $year): array
    {
        $from = CarbonImmutable::create($year, $this->landlord->fiscal_year_start_month ?: 7, 1)->startOfDay();

        return [$from, $from->addYear()->subDay()->endOfDay()];
    }

    public function fiscalYearLabel(int $year): string
    {
        return ($this->landlord->fiscal_year_start_month ?: 7) === 1 ? (string) $year : 'FY '.$year.'/'.substr((string) ($year + 1), -2);
    }

    // --- Income & expenses ----------------------------------------------------------

    /**
     * Income for a period: [category => [estate_id => amount]].
     * Categories: rent, advance, utilities, deposit_deductions.
     */
    public function income(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        $add = function (string $category, ?int $estate, float $amount) use (&$out) {
            if (abs($amount) < 0.005) {
                return;
            }
            $key = $estate ?? self::UNASSIGNED;
            $out[$category][$key] = round(($out[$category][$key] ?? 0) + $amount, 2);
        };
        $estateOfLease = $this->leaseEstates();

        if ($this->landlord->booksBasis() === 'accrual') {
            Invoice::whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
                ->whereIn('type', ['rent', 'utility'])->where('status', '!=', 'cancelled')
                ->get(['id', 'type', 'amount', 'lease_id'])
                ->each(fn (Invoice $i) => $add($i->type === 'rent' ? 'rent' : 'utilities', $estateOfLease[$i->lease_id] ?? null, (float) $i->amount));
        } else {
            // Money received, by what it was put against (and when it came in).
            PaymentAllocation::query()
                ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
                ->join('invoices', 'invoices.id', '=', 'payment_allocations.invoice_id')
                ->where('payments.status', '!=', 'cancelled')
                ->whereDate('payments.payment_date', '>=', $from->toDateString())->whereDate('payments.payment_date', '<=', $to->toDateString())
                ->whereIn('invoices.type', ['rent', 'utility'])
                ->get(['invoices.type as invoice_type', 'invoices.lease_id', 'payment_allocations.amount'])
                ->each(fn ($a) => $add($a->invoice_type === 'rent' ? 'rent' : 'utilities', $estateOfLease[$a->lease_id] ?? null, (float) $a->amount));

            // Received but not yet put against an invoice: rent in advance.
            Payment::where('status', '!=', 'cancelled')
                ->whereDate('payment_date', '>=', $from->toDateString())->whereDate('payment_date', '<=', $to->toDateString())
                ->withSum('allocations as allocated', 'amount')->get()
                ->each(fn (Payment $p) => $add('advance', null, max(0, (float) $p->amount - (float) $p->allocated)));
        }

        SecurityDepositLedger::whereIn('type', ['deduction', 'forfeit'])
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->get(['lease_id', 'amount'])
            ->each(fn ($d) => $add('deposit_deductions', $estateOfLease[$d->lease_id] ?? null, (float) $d->amount));

        return $out;
    }

    /**
     * Expenses for a period: [label => [estate_id => amount]], including
     * Primora's charges, bad debts (accrual) and deductible levies paid.
     */
    public function expenses(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        $add = function (string $label, ?int $estate, float $amount) use (&$out) {
            if (abs($amount) < 0.005) {
                return;
            }
            $key = $estate ?? self::UNASSIGNED;
            $out[$label][$key] = round(($out[$label][$key] ?? 0) + $amount, 2);
        };

        Expense::with(['expenseItem', 'unit'])
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->get()
            ->each(fn (Expense $e) => $add($e->expenseItem?->name ?? 'Other expenses', $e->estate_id ?? $e->unit?->estate_id, (float) $e->amount));

        if ($this->landlord->booksBasis() === 'accrual') {
            Invoice::where('status', 'written_off')->whereIn('type', ['rent', 'utility'])
                ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
                ->withSum('allocations as paid', 'amount')->get()
                ->each(fn (Invoice $i) => $add('Bad debts written off', $this->leaseEstates()[$i->lease_id] ?? null, max(0, (float) $i->amount - (float) $i->paid)));
        }

        // Paid to Primora.
        $primora = PaymentTransaction::where('landlord_id', $this->landlord->id)->where('status', PaymentTransaction::SUCCEEDED)
            ->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()]);
        $add('Primora subscription', null, (float) (clone $primora)->where('purpose', 'subscription')->sum('amount'));
        $add('SMS credit (Primora)', null, (float) (clone $primora)->where('purpose', 'sms')->sum('amount'));
        $add('Mobile money collection fees', null, (float) (clone $primora)->where('purpose', 'rent')->where('fee_bearer', 'landlord')->sum('fee_amount'));
        $add('Payout transfer charges', null, (float) Liquidation::where('status', Liquidation::COMPLETED)
            ->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])->sum('fee'));

        // Deductible levies paid (property rates, ground rent, licences).
        TaxPayment::with('landlordTax.template')
            ->whereDate('paid_on', '>=', $from->toDateString())->whereDate('paid_on', '<=', $to->toDateString())->get()
            ->filter(fn (TaxPayment $p) => $p->landlordTax?->template?->deductible)
            ->each(fn (TaxPayment $p) => $add($p->landlordTax->template->name, $p->landlordTax->estate_id, (float) $p->amount));

        ksort($out);

        return $out;
    }

    public const INCOME_LABELS = [
        'rent' => 'Rent received',
        'advance' => 'Rent received in advance (not yet invoiced)',
        'utilities' => 'Utilities recharged to tenants',
        'deposit_deductions' => 'Deposit deductions & forfeits',
    ];

    /** Gross rental income for tax: rent (incl. in advance) and deposit deductions; utility recharges excluded. */
    public function grossRentalIncome(array $income): float
    {
        return round(collect(['rent', 'advance', 'deposit_deductions'])->sum(fn ($c) => array_sum($income[$c] ?? [])), 2);
    }

    // --- Reports --------------------------------------------------------------------

    public function incomeStatement(CarbonImmutable $from, CarbonImmutable $to): Report
    {
        $income = $this->income($from, $to);
        $expenses = $this->expenses($from, $to);
        $basis = $this->landlord->booksBasis() === 'accrual' ? 'Accrual basis' : 'Cash basis';

        $report = $this->report('income-statement', 'Income statement', $from->format('d M Y').' to '.$to->format('d M Y').' · '.$basis, ['Amount (UGX)']);
        $incomeRows = collect(self::INCOME_LABELS)->filter(fn ($l, $k) => isset($income[$k]))
            ->map(fn ($label, $k) => ['code' => null, 'name' => $label, 'values' => [round(array_sum($income[$k]), 2)], 'account_id' => null])->values()->all();
        $expenseRows = collect($expenses)->map(fn ($byEstate, $label) => ['code' => null, 'name' => $label, 'values' => [round(array_sum($byEstate), 2)], 'account_id' => null])->values()->all();

        // Rental income tax and LST paid: not deductible, so shown after net income.
        $taxRows = TaxPayment::with('landlordTax.template')
            ->whereDate('paid_on', '>=', $from->toDateString())->whereDate('paid_on', '<=', $to->toDateString())->get()
            ->reject(fn (TaxPayment $p) => $p->landlordTax?->template?->deductible)
            ->groupBy(fn (TaxPayment $p) => $p->landlordTax?->template?->name ?? 'Taxes')
            ->map(fn ($payments, $name) => ['code' => null, 'name' => $name, 'values' => [round($payments->sum('amount'), 2)], 'account_id' => null])
            ->values()->all();

        $report->section('income', 'Income', $incomeRows, 'Total income')
            ->section('expense', 'Expenses', $expenseRows, 'Total expenses')
            ->total($taxRows ? 'Net income before tax' : 'Net income', ['income' => 1, 'expense' => -1], !$taxRows);
        if ($taxRows) {
            $report->section('tax', 'Taxes on income paid', $taxRows, 'Total taxes')
                ->total('Net income after tax', ['income' => 1, 'expense' => -1, 'tax' => -1], true);
        }
        $report
            ->note($basis === 'Cash basis'
                ? 'Cash basis: income when received and expenses when paid. Security deposits are held for tenants and are not income.'
                : 'Accrual basis: income when invoiced; written-off invoices are shown as bad debts. Security deposits are held for tenants and are not income.')
            ->note('Includes charges paid to Primora and deductible levies (property rates, ground rent, licences) recorded under Taxes.');

        return $report;
    }

    public function byProperty(CarbonImmutable $from, CarbonImmutable $to): Report
    {
        $income = $this->income($from, $to);
        $expenses = $this->expenses($from, $to);
        $names = Estate::orderBy('name')->pluck('name', 'id');

        $totals = [];
        foreach ($income as $byEstate) {
            foreach ($byEstate as $estate => $amount) {
                $totals[$estate][0] = ($totals[$estate][0] ?? 0) + $amount;
            }
        }
        foreach ($expenses as $byEstate) {
            foreach ($byEstate as $estate => $amount) {
                $totals[$estate][1] = ($totals[$estate][1] ?? 0) + $amount;
            }
        }

        $rows = [];
        foreach ($names as $id => $name) {
            if (isset($totals[$id])) {
                $rows[] = $this->propertyRow($name, $totals[$id]);
            }
        }
        if (isset($totals[self::UNASSIGNED])) {
            $rows[] = $this->propertyRow('Not linked to a property (general)', $totals[self::UNASSIGNED]);
        }

        $report = $this->report('by-property', 'Income & expenses by property', $from->format('d M Y').' to '.$to->format('d M Y'), ['Income', 'Expenses', 'Net']);
        $report->section('estates', null, $rows, 'Total')
            ->note('General items include rent paid in advance, Primora charges, and expenses not linked to an estate or unit.');

        return $report;
    }

    /** Taxes and levies for a fiscal year: due (with the working), paid, balance. */
    public function taxReport(int $year): Report
    {
        [$from, $to] = $this->fiscalYearRange($year);
        $result = $this->computeTaxes($year);

        $report = $this->report('taxes', 'Taxes & levies', $this->fiscalYearLabel($year).' ('.$from->format('d M Y').' to '.$to->format('d M Y').')', ['Due', 'Paid', 'Balance']);
        $report->section('taxes', null, collect($result['lines'])->map(fn ($l) => [
            'code' => null, 'name' => $l['label'], 'values' => [$l['due'], $l['paid'], round($l['due'] - $l['paid'], 2)], 'account_id' => null,
            'note' => $l['authority'].' · '.$l['working'],
        ])->all(), 'Total');
        $report->note('Gross rental income for the year: UGX '.number_format($result['gross_rental'], 2).' (rent, rent in advance and deposit deductions; utility recharges excluded).');
        $report->note('These are estimates to help you file, using '.($this->landlord->taxpayer_type === 'company' ? 'company' : 'individual').' rates you can adjust under Books settings. Confirm with URA and your local council.');

        return $report;
    }

    /**
     * @return array{gross_rental: float, expenses: float, lines: list<array{tax: LandlordTax, label: string, authority: string, due: float, paid: float, working: string}>}
     */
    public function computeTaxes(int $year): array
    {
        [$from, $to] = $this->fiscalYearRange($year);
        $income = $this->income($from, $to);
        $gross = $this->grossRentalIncome($income);
        $expenseTotal = round(collect($this->expenses($from, $to))->sum(fn ($e) => array_sum($e)), 2);

        $taxes = LandlordTax::with(['template', 'estate'])->where('is_enabled', true)->whereHas('template', fn ($q) => $q->where('is_active', true))->get()->sortBy(fn ($t) => [$t->template->method === 'rental_income' ? 1 : 0, $t->template->sort_order, $t->estate?->name]);
        $paid = TaxPayment::where('fiscal_year', $year)->selectRaw('landlord_tax_id, SUM(amount) as total')->groupBy('landlord_tax_id')->pluck('total', 'landlord_tax_id');

        $lines = [];
        $deductibleLevies = 0.0;
        foreach ($taxes as $tax) {
            [$due, $working] = $this->due($tax, $gross, $expenseTotal, $deductibleLevies);
            if ($tax->template->deductible) {
                $deductibleLevies += $due;
            }
            $lines[] = [
                'tax' => $tax, 'label' => $tax->label(), 'authority' => $tax->template->authority,
                'due' => $due, 'paid' => round((float) ($paid[$tax->id] ?? 0), 2), 'working' => $working,
            ];
        }

        return ['gross_rental' => $gross, 'expenses' => $expenseTotal, 'lines' => $lines];
    }

    /** @return array{0: float, 1: string} amount due and how it was worked out */
    protected function due(LandlordTax $tax, float $gross, float $expenses, float $deductibleLevies): array
    {
        $p = $tax->template->params ?? [];
        $n = fn ($v) => number_format((float) $v);

        switch ($tax->template->method) {
            case 'rental_income':
                if ($this->landlord->taxpayer_type === 'company') {
                    $rate = (float) $tax->setting('company_rate', $p['company']['rate'] ?? 30);
                    $cap = (float) $tax->setting('expense_cap_percent', $p['company']['expense_cap_percent'] ?? 50);
                    $allowable = $expenses; // levies paid are already among the expenses
                    $limit = round($gross * $cap / 100, 2);
                    $deduct = min($allowable, $limit);
                    $chargeable = max(0, $gross - $deduct);
                    $due = round($chargeable * $rate / 100, 2);

                    return [$due, "{$rate}% × (gross {$n($gross)} − expenses {$n($deduct)}".($allowable > $limit ? " (capped at {$cap}%)" : '').")"];
                }
                $rate = (float) $tax->setting('rate', $p['individual']['rate'] ?? 12);
                $threshold = (float) $tax->setting('threshold', $p['individual']['threshold'] ?? 0);
                $due = round(max(0, $gross - $threshold) * $rate / 100, 2);

                return [$due, "{$rate}% × (gross {$n($gross)} − threshold {$n($threshold)})"];

            case 'bands':
                if ($tax->setting('amount') !== null) {
                    return [round((float) $tax->setting('amount'), 2), 'Amount you set'];
                }
                $monthly = round($gross / 12, 2);
                // The first band whose upper limit covers the income (so 100,000.50 lands in "100,001 – 200,000", not between bands).
                $band = collect($p['bands'] ?? [])->sortBy('min')->first(fn ($b) => $b['max'] === null || $monthly <= (float) $b['max']);
                if ($band) {
                    return [(float) $band['amount'], "Band for average monthly income {$n($monthly)}"];
                }

                return [0.0, "No band for average monthly income {$n($monthly)}"];

            case 'percent_of_value':
                $rate = (float) $tax->setting('rate', $p['rate'] ?? 0);
                $value = (float) $tax->setting('rateable_value', 0);

                return [round($value * $rate / 100, 2), $value > 0 ? "{$rate}% × rateable value {$n($value)}" : 'Enter the rateable value in Books settings'];

            case 'fixed':
            default:
                $amount = (float) $tax->setting('amount', $p['amount'] ?? 0);

                return [round($amount, 2), $amount > 0 ? 'Annual amount' : 'Enter the annual amount in Books settings'];
        }
    }

    // ------------------------------------------------------------------------------

    protected function report(string $slug, string $title, string $period, array $columns): Report
    {
        $report = new Report($slug, $title, $period, $columns, showCodes: false);
        $report->entity = $this->landlord->name;
        $report->entityLine = $this->landlord->tin ? 'TIN '.$this->landlord->tin : null;

        return $report;
    }

    protected function propertyRow(string $name, array $t): array
    {
        $in = round($t[0] ?? 0, 2);
        $out = round($t[1] ?? 0, 2);

        return ['code' => null, 'name' => $name, 'values' => [$in, $out, round($in - $out, 2)], 'account_id' => null];
    }

    /** lease_id => estate_id */
    protected function leaseEstates(): Collection
    {
        return $this->leaseEstates ??= \App\Models\Lease::query()
            ->join('units', 'units.id', '=', 'leases.unit_id')
            ->pluck('units.estate_id', 'leases.id');
    }

    protected ?Collection $leaseEstates = null;
}
