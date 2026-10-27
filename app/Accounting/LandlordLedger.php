<?php

namespace App\Accounting;

use App\Accounting\Reports\Report;
use App\Models\Expense;
use App\Models\ExpenseItems;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\LandlordSettlement;
use App\Models\Liquidation;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\SecurityDepositLedger;
use App\Models\TaxPayment;
use App\Models\TaxTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A landlord's double-entry books, derived from what they record (payments,
 * invoices, deposits, expenses, tax payments) and Primora's records (rent it
 * collected for them, its charges, payouts). Nothing is stored: every report
 * re-derives the journal, so it always matches the records.
 *
 * Follows the landlord's basis. Cash: money received is income straight away.
 * Accrual: invoices raise what tenants owe; money received settles it, and
 * money not yet put against an invoice is rent paid in advance (a liability).
 *
 * Runs in the landlord's scope (CurrentLandlord).
 */
class LandlordLedger
{
    /** The default chart: key => [code, name, type, description]. */
    public const ACCOUNTS = [
        'cash' => ['1000', 'Cash, bank & mobile money', 'asset', 'Money you hold: payments you recorded, payouts from Primora, less what you paid out.'],
        'primora' => ['1100', 'Rent collected by Primora', 'asset', 'Rent your tenants paid through Primora, held for you until paid out.'],
        'receivable' => ['1200', 'Tenants owe you', 'asset', 'Invoices raised and not yet paid (accrual basis).'],
        'deposits' => ['2000', 'Security deposits held', 'liability', "Tenants' deposits: theirs until refunded, deducted or forfeited."],
        'prepaid' => ['2100', 'Rent paid in advance', 'liability', 'Money received but not yet put against an invoice (accrual basis).'],
        'retained' => ['3000', 'Retained earnings', 'equity', 'Net income of earlier fiscal years, kept in the business.'],
        'rent' => ['4000', 'Rent', 'income', 'Rent invoiced (accrual) or received (cash).'],
        'advance' => ['4010', 'Rent received in advance', 'income', 'Money received but not yet put against an invoice (cash basis).'],
        'utilities' => ['4100', 'Utilities recharged', 'income', 'Utility bills invoiced to tenants and customers.'],
        'deposit_income' => ['4200', 'Deposit deductions & forfeits', 'income', 'Deposits kept for damage, arrears or forfeit.'],
        'bad_debts' => ['5000', 'Bad debts written off', 'expense', 'Invoices you wrote off as unrecoverable (accrual basis).'],
        'subscription' => ['5100', 'Primora subscription', 'expense', 'Your Primora plan.'],
        'sms' => ['5110', 'SMS credit', 'expense', 'SMS bundles bought from Primora.'],
        'collection_fees' => ['5120', 'Mobile money collection fees', 'expense', 'Fees on rent collected by mobile money, where you bear them.'],
        'payout_charges' => ['5130', 'Payout transfer charges', 'expense', 'Charges on payouts to your bank or mobile money.'],
        'other_expenses' => ['6000', 'Other expenses', 'expense', 'Expenses without an expense item.'],
        'income_taxes' => ['8000', 'Income tax & local service tax', 'expense', 'Rental income tax and LST you paid (not a deductible expense).'],
    ];

    public const TYPES = [
        'asset' => 'Assets',
        'liability' => 'Liabilities',
        'equity' => 'Equity',
        'income' => 'Income',
        'expense' => 'Expenses',
    ];

    protected LandlordBooks $books;

    protected ?Collection $journal = null;

    public function __construct(protected Landlord $landlord)
    {
        $this->books = LandlordBooks::for($landlord);
    }

    public static function for(Landlord $landlord): self
    {
        return new self($landlord);
    }

    public function accrual(): bool
    {
        return $this->landlord->booksBasis() === 'accrual';
    }

    // --- Chart of accounts ----------------------------------------------------------

    /**
     * Every account: the defaults for this basis, one per expense item, one per deductible levy.
     *
     * @return Collection<string, array{key: string, code: string, name: string, type: string, description: string}>
     */
    public function accounts(): Collection
    {
        $skip = $this->accrual() ? ['advance'] : ['receivable', 'prepaid', 'bad_debts'];
        $accounts = collect(self::ACCOUNTS)->except($skip)
            ->map(fn ($a, $key) => ['key' => $key, 'code' => $a[0], 'name' => $a[1], 'type' => $a[2], 'description' => $a[3]]);

        foreach (ExpenseItems::orderBy('id')->get(['id', 'name']) as $item) {
            $accounts['item:'.$item->id] = ['key' => 'item:'.$item->id, 'code' => '6'.str_pad((string) $item->id, 3, '0', STR_PAD_LEFT), 'name' => $item->name, 'type' => 'expense', 'description' => 'Expenses recorded under this expense item.'];
        }
        foreach (TaxTemplate::where('deductible', true)->orderBy('sort_order')->get(['id', 'name']) as $t) {
            $accounts['tax:'.$t->id] = ['key' => 'tax:'.$t->id, 'code' => '7'.str_pad((string) $t->id, 3, '0', STR_PAD_LEFT), 'name' => $t->name, 'type' => 'expense', 'description' => 'Paid to your council or land owner; a deductible expense.'];
        }

        return $accounts->sortBy('code', SORT_STRING);
    }

    public function account(string $key): ?array
    {
        return $this->accounts()->get($key) ?? $this->fallbackAccount($key);
    }

    /** Debit-normal accounts show debit − credit; the rest credit − debit. */
    public static function debitNormal(string $type): bool
    {
        return in_array($type, ['asset', 'expense'], true);
    }

    // --- The journal --------------------------------------------------------------------

    /**
     * Every journal line, oldest first: [date, ref, memo, account, debit, credit].
     *
     * @return Collection<int, array{date: string, ref: string, memo: string, account: string, debit: float, credit: float}>
     */
    public function journal(): Collection
    {
        if ($this->journal) {
            return $this->journal;
        }

        $lines = [];
        $post = function ($date, string $ref, string $memo, array $entries) use (&$lines) {
            $date = CarbonImmutable::parse($date)->toDateString();
            foreach ($entries as [$account, $debit, $credit]) {
                $debit = round((float) $debit, 2);
                $credit = round((float) $credit, 2);
                if (abs($debit) >= 0.005 || abs($credit) >= 0.005) {
                    $lines[] = compact('date', 'ref', 'memo', 'account', 'debit', 'credit');
                }
            }
        };
        $accrual = $this->accrual();

        // Rent tenants paid through Primora: received into "Rent collected by Primora".
        $primoraTx = PaymentTransaction::where('landlord_id', $this->landlord->id)->where('status', PaymentTransaction::SUCCEEDED)->get();
        $viaPrimora = $primoraTx->where('purpose', 'rent')->mapWithKeys(fn ($t) => [(int) (($t->context ?? [])['payment_id'] ?? 0) => true]);

        // Money received, split by what it was put against.
        Payment::with(['payable', 'allocations.invoice:id,type,number'])->where('status', '!=', 'cancelled')->get()
            ->each(function (Payment $p) use ($post, $accrual, $viaPrimora) {
                $by = $p->allocations->groupBy(fn ($a) => $a->invoice?->type ?? 'none')->map->sum('amount');
                $rent = (float) ($by['rent'] ?? 0);
                $utilities = (float) ($by['utility'] ?? 0);
                $deposit = (float) ($by['security_deposit'] ?? 0);
                $unallocated = max(0, (float) $p->amount - $p->allocations->sum('amount'));
                $total = $rent + $utilities + $deposit + $unallocated;
                $memo = 'Payment'.($p->display_payer_name ? ' from '.$p->display_payer_name : '');

                $post($p->payment_date, $p->number ?? 'Payment #'.$p->id, $memo, $accrual
                    ? [[isset($viaPrimora[$p->id]) ? 'primora' : 'cash', $total, 0], ['receivable', 0, $rent + $utilities], ['deposits', 0, $deposit], ['prepaid', 0, $unallocated]]
                    : [[isset($viaPrimora[$p->id]) ? 'primora' : 'cash', $total, 0], ['rent', 0, $rent], ['utilities', 0, $utilities], ['deposits', 0, $deposit], ['advance', 0, $unallocated]]);
            });

        // Invoices raised (accrual): what tenants owe; written-off balances become bad debts.
        if ($accrual) {
            Invoice::whereIn('type', ['rent', 'utility'])->where('status', '!=', 'cancelled')->withSum('allocations as paid', 'amount')->get()
                ->each(function (Invoice $i) use ($post) {
                    $ref = $i->number ?? 'Invoice #'.$i->id;
                    $post($i->invoice_date, $ref, 'Invoice: '.$i->title, [['receivable', $i->amount, 0], [$i->type === 'rent' ? 'rent' : 'utilities', 0, $i->amount]]);
                    if ($i->status === 'written_off') {
                        $unpaid = max(0, (float) $i->amount - (float) $i->paid);
                        $post($i->invoice_date, $ref, 'Written off: '.$i->title, [['bad_debts', $unpaid, 0], ['receivable', 0, $unpaid]]);
                    }
                });
        }

        // Security deposits. Deposits paid against a deposit invoice came in as payments above.
        SecurityDepositLedger::with('lease:id,number')->get()->each(function (SecurityDepositLedger $d) use ($post) {
            $ref = $d->lease?->number ?? 'Lease';
            match ($d->type) {
                'deposit' => str_starts_with((string) $d->notes, 'Auto-tracked from invoice') ? null
                    : $post($d->date, $ref, 'Security deposit received', [['cash', $d->amount, 0], ['deposits', 0, $d->amount]]),
                'refund' => $post($d->date, $ref, 'Security deposit refunded', [['deposits', $d->amount, 0], ['cash', 0, $d->amount]]),
                'deduction', 'forfeit' => $post($d->date, $ref, 'Security deposit '.($d->type === 'forfeit' ? 'forfeited' : 'deduction'), [['deposits', $d->amount, 0], ['deposit_income', 0, $d->amount]]),
                default => null,
            };
        });

        // Expenses paid.
        Expense::with('expenseItem:id,name')->get()->each(fn (Expense $e) => $post($e->date, $e->number ?? 'Expense #'.$e->id,
            $e->expenseItem?->name ?? 'Expense', [[$e->expense_item_id ? 'item:'.$e->expense_item_id : 'other_expenses', $e->amount, 0], ['cash', 0, $e->amount]]));

        // Paid to Primora, and fees Primora kept from rent it collected.
        foreach ($primoraTx as $t) {
            match ($t->purpose) {
                'subscription' => $post($t->completed_at, $t->reference, 'Primora subscription', [['subscription', $t->amount, 0], ['cash', 0, $t->amount]]),
                'sms' => $post($t->completed_at, $t->reference, 'SMS credit', [['sms', $t->amount, 0], ['cash', 0, $t->amount]]),
                'rent' => $t->fee_bearer === 'landlord' && (float) $t->fee_amount > 0
                    ? $post($t->completed_at, $t->reference, 'Mobile money collection fee', [['collection_fees', $t->fee_amount, 0], ['primora', 0, $t->fee_amount]])
                    : null,
                default => null,
            };
        }

        // Rent Primora paid out to the landlord.
        Liquidation::where('status', Liquidation::COMPLETED)->get()->each(fn (Liquidation $l) => $post($l->completed_at, $l->reference, 'Payout from Primora',
            [['cash', $l->net_amount, 0], ['payout_charges', $l->fee, 0], ['primora', 0, $l->amount]]));
        LandlordSettlement::where('landlord_id', $this->landlord->id)->get()->each(fn (LandlordSettlement $s) => $post($s->settled_on, $s->reference ?: 'Settlement #'.$s->id,
            'Paid out by Primora', [['cash', $s->amount, 0], ['primora', 0, $s->amount]]));

        // Taxes and levies paid.
        TaxPayment::with('landlordTax.template', 'landlordTax.estate')->get()->each(function (TaxPayment $p) use ($post) {
            $template = $p->landlordTax?->template;
            $post($p->paid_on, $p->reference ?: 'Tax payment #'.$p->id, $p->landlordTax?->label() ?? 'Tax payment',
                [[$template?->deductible ? 'tax:'.$template->id : 'income_taxes', $p->amount, 0], ['cash', 0, $p->amount]]);
        });

        return $this->journal = collect($lines)->sortBy([['date', 'asc']])->values();
    }

    /** Net debit (debit − credit) per account for lines dated between $from and $to (either may be open). */
    public function movements(?CarbonImmutable $from, ?CarbonImmutable $to): Collection
    {
        $f = $from?->toDateString();
        $t = $to?->toDateString();

        return $this->journal()
            ->filter(fn ($l) => ($f === null || $l['date'] >= $f) && ($t === null || $l['date'] <= $t))
            ->groupBy('account')
            ->map(fn ($lines) => round($lines->sum('debit') - $lines->sum('credit'), 2));
    }

    /**
     * Balances as at a date, in each account's natural sign: balance-sheet
     * accounts since the start; income and expenses for the fiscal year to
     * date, with earlier years' net income in Retained earnings.
     *
     * @return Collection<string, float>
     */
    public function balancesAt(CarbonImmutable $asAt): Collection
    {
        [$fyStart] = $this->books->fiscalYearRange($this->books->fiscalYearOf($asAt));
        $all = $this->movements(null, $asAt);
        $year = $this->movements($fyStart, $asAt);
        $accounts = $this->accountsWith($all->keys());

        $out = collect();
        foreach ($accounts as $key => $a) {
            $net = in_array($a['type'], ['income', 'expense'], true) ? ($year[$key] ?? 0) : ($all[$key] ?? 0);
            $out[$key] = round(self::debitNormal($a['type']) ? $net : -$net, 2);
        }
        // Earlier years' income less expenses, as a credit balance.
        $before = $this->movements(null, $fyStart->subDay());
        $out['retained'] = round(-$accounts->filter(fn ($a) => in_array($a['type'], ['income', 'expense'], true))->keys()->sum(fn ($k) => $before[$k] ?? 0), 2);

        return $out;
    }

    // --- Reports --------------------------------------------------------------------------

    public function trialBalance(CarbonImmutable $asAt): Report
    {
        $balances = $this->balancesAt($asAt);
        $accounts = $this->accountsWith($balances->keys());

        $report = $this->report('trial-balance', 'Trial balance', 'As at '.$asAt->format('d M Y').' · '.$this->basisLabel(), ['Debit', 'Credit']);
        foreach (self::TYPES as $type => $title) {
            $rows = $accounts->where('type', $type)
                ->filter(fn ($a) => abs($balances[$a['key']] ?? 0) >= 0.005 || in_array($a['key'], ['cash', 'retained'], true))
                ->map(function ($a) use ($balances) {
                    $b = $balances[$a['key']] ?? 0;
                    $debit = self::debitNormal($a['type']) ? $b : -$b;

                    return ['code' => $a['code'], 'name' => $a['name'], 'values' => [max(0, $debit), max(0, -$debit)], 'account_id' => $a['key']];
                });
            if ($rows->isNotEmpty()) {
                $report->section($type, $title, $rows->values()->all());
            }
        }
        $report->total('Total', collect($report->sections)->mapWithKeys(fn ($s) => [$s['key'] => 1])->all(), true);

        $gap = $report->totalValue($report->totals[0], 0) - $report->totalValue($report->totals[0], 1);
        $report->check = [abs($gap) < 0.01 ? 'Debits equal credits' : 'Out of balance by '.number_format(abs($gap), 2), abs($gap) < 0.01];
        [$fyStart] = $this->books->fiscalYearRange($this->books->fiscalYearOf($asAt));
        $report->note('Income and expenses are for '.$this->books->fiscalYearLabel($this->books->fiscalYearOf($asAt)).' to date (from '.$fyStart->format('d M Y').'); earlier years are in Retained earnings.');

        return $report;
    }

    public function balanceSheet(CarbonImmutable $asAt, CarbonImmutable $compareAt): Report
    {
        $now = $this->balancesAt($asAt);
        $then = $this->balancesAt($compareAt);
        $accounts = $this->accountsWith($now->keys()->merge($then->keys()));
        $rows = fn (string $type) => $accounts->where('type', $type)
            ->filter(fn ($a) => in_array($a['key'], ['cash', 'retained'], true) || abs($now[$a['key']] ?? 0) >= 0.005 || abs($then[$a['key']] ?? 0) >= 0.005)
            ->map(fn ($a) => ['code' => $a['code'], 'name' => $a['name'], 'values' => [$now[$a['key']] ?? 0.0, $then[$a['key']] ?? 0.0], 'account_id' => $a['key']])
            ->values()->all();
        $earnings = fn (Collection $b) => round($accounts->where('type', 'income')->keys()->sum(fn ($k) => $b[$k] ?? 0) - $accounts->where('type', 'expense')->keys()->sum(fn ($k) => $b[$k] ?? 0), 2);

        $equity = $rows('equity');
        $equity[] = ['code' => null, 'name' => 'Net income this fiscal year', 'values' => [$earnings($now), $earnings($then)], 'account_id' => null];

        $report = $this->report('balance-sheet', 'Balance sheet', 'As at '.$asAt->format('d M Y').' · '.$this->basisLabel(), [$asAt->format('d M Y'), $compareAt->format('d M Y')]);
        $report->section('assets', 'What you have', $rows('asset'), 'Total assets')
            ->section('liabilities', 'What you owe', $rows('liability'), 'Total liabilities')
            ->section('equity', 'Your equity', $equity, 'Total equity')
            ->total('Total liabilities and equity', ['liabilities' => 1, 'equity' => 1], true);

        $gap = $report->sectionTotal('assets', 0) - $report->totalValue($report->totals[0], 0);
        $report->check = [abs($gap) < 0.01 ? 'Assets equal liabilities plus equity' : 'Out of balance by '.number_format(abs($gap), 2), abs($gap) < 0.01];
        if (($now['cash'] ?? 0) < 0) {
            $report->note('Cash is negative: more was recorded as paid out than received. Money you had before you started recording in Primora, or income not yet recorded, would explain it.');
        }
        $report->note('Built from your records in Primora. Equity is what you have built up from rent since you started recording here.');

        return $report;
    }

    /**
     * One account's lines over a period, with the opening and running balance.
     *
     * @return array{account: array, opening: float, lines: Collection, closing: float}
     */
    public function accountLedger(string $key, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $account = $this->account($key);
        $sign = self::debitNormal($account['type']) ? 1 : -1;
        // Income and expenses start each fiscal year at nil.
        $openFrom = in_array($account['type'], ['income', 'expense'], true) ? $this->books->fiscalYearRange($this->books->fiscalYearOf($from))[0] : null;
        $opening = $sign * ($this->movements($openFrom, $from->subDay())[$key] ?? 0);
        if ($openFrom && $openFrom->greaterThanOrEqualTo($from)) {
            $opening = 0.0;
        }

        $running = $opening;
        $lines = $this->journal()->where('account', $key)
            ->filter(fn ($l) => $l['date'] >= $from->toDateString() && $l['date'] <= $to->toDateString())
            ->map(function ($l) use (&$running, $sign) {
                $running = round($running + $sign * ($l['debit'] - $l['credit']), 2);

                return $l + ['balance' => $running];
            })->values();

        return ['account' => $account, 'opening' => round($opening, 2), 'lines' => $lines, 'closing' => $running];
    }

    // ------------------------------------------------------------------------------

    public function basisLabel(): string
    {
        return $this->accrual() ? 'Accrual basis' : 'Cash basis';
    }

    /** The chart plus any account that has lines but isn't in it (e.g. a deleted expense item). */
    protected function accountsWith(Collection $keys): Collection
    {
        $accounts = $this->accounts();
        foreach ($keys as $key) {
            if (!$accounts->has($key) && ($fallback = $this->fallbackAccount($key))) {
                $accounts[$key] = $fallback;
            }
        }

        return $accounts->sortBy('code', SORT_STRING);
    }

    protected function fallbackAccount(string $key): ?array
    {
        if (isset(self::ACCOUNTS[$key])) {
            $a = self::ACCOUNTS[$key];

            return ['key' => $key, 'code' => $a[0], 'name' => $a[1], 'type' => $a[2], 'description' => $a[3]];
        }
        if (preg_match('/^(item|tax):(\d+)$/', $key, $m)) {
            return ['key' => $key, 'code' => ($m[1] === 'item' ? '6' : '7').str_pad($m[2], 3, '0', STR_PAD_LEFT), 'name' => ($m[1] === 'item' ? 'Expense item #' : 'Levy #').$m[2], 'type' => 'expense', 'description' => ''];
        }

        return null;
    }

    protected function report(string $slug, string $title, string $period, array $columns): Report
    {
        $report = new Report($slug, $title, $period, $columns, showCodes: true);
        $report->entity = $this->landlord->name;
        $report->entityLine = $this->landlord->tin ? 'TIN '.$this->landlord->tin : null;

        return $report;
    }
}
