<?php

namespace App\Http\Controllers\Staff;

use App\Accounting\Ledger;
use App\Accounting\Reports\FinancialReports;
use App\Accounting\Reports\Report;
use App\Accounting\Reports\ReportPdf;
use App\Accounting\Reports\ReportSpreadsheet;
use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Landlord;
use App\Models\LedgerAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Primora's books: chart of accounts, ledgers, journal, P&L, and manual entries. */
class BooksController extends Controller
{
    public const PERIODS = [
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_quarter' => 'This quarter',
        'this_year' => 'This year',
        'last_12' => 'Last 12 months',
        'all' => 'All time',
        'custom' => 'Custom',
    ];

    public function __construct(protected Ledger $ledger)
    {
    }

    /** What the balances mean: money held, what belongs to landlords, Primora's own position. */
    public function index()
    {
        $balances = $this->ledger->balances();
        $by = fn (string $key) => (float) ($balances->firstWhere('system_key', $key)?->balance ?? 0);

        $cash = $balances->where('type', 'asset')->sum('balance');
        $landlords = $by('landlord_payable');
        $liabilities = $balances->where('type', 'liability')->sum('balance');
        $month = $this->ledger->profitAndLoss(now()->startOfMonth()->toImmutable(), now()->toImmutable());
        $allTime = $this->ledger->profitAndLoss(CarbonImmutable::create(2000), now()->toImmutable());

        return view('staff.books.index', [
            'balances' => $balances,
            'cash' => $cash,
            'landlords' => $landlords,
            'liabilities' => $liabilities,
            'equity' => $balances->where('type', 'equity')->sum('balance'),
            'own' => $cash - $liabilities,
            'month' => $month,
            'retained' => $allTime['profit'],
            'recent' => JournalEntry::with('lines.account')->latest('id')->limit(8)->get(),
            'moneyAccounts' => LedgerAccount::moneyAccounts(),
            'expenseAccounts' => LedgerAccount::where('type', 'expense')->where('is_active', true)->orderBy('code')->get(),
            'allAccounts' => LedgerAccount::where('is_active', true)->orderBy('code')->get(),
            'unbalanced' => $this->unbalancedTotal(),
        ]);
    }

    // --- Chart of accounts ------------------------------------------------------

    public function accounts()
    {
        return view('staff.books.accounts', [
            'accounts' => $this->ledger->balances()->groupBy('type'),
        ]);
    }

    public function storeAccount(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10', 'regex:/^[0-9]{3,6}$/', 'unique:ledger_accounts,code'],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(array_keys(LedgerAccount::TYPES))],
            'description' => ['nullable', 'string', 'max:300'],
        ], ['code.regex' => 'Use a number, e.g. 5300.']);

        LedgerAccount::create($data + ['is_active' => true]);

        return back()->with('success', "Account {$data['code']} {$data['name']} added.");
    }

    public function updateAccount(Request $request, LedgerAccount $ledgerAccount)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:300'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Accounts the app posts to automatically always stay active.
        $active = $ledgerAccount->system_key ? true : $request->boolean('is_active');
        $ledgerAccount->update(['name' => $data['name'], 'description' => $data['description'] ?? null, 'is_active' => $active]);

        return back()->with('success', 'Account updated.');
    }

    /** One account's lines, with a running balance; for landlord funds, per-landlord totals too. */
    public function ledger(Request $request, LedgerAccount $ledgerAccount)
    {
        [$period, $from, $to] = $this->range($request, 'this_year');
        $landlordId = $request->integer('landlord') ?: null;

        $opening = $from ? $this->ledger->balance($ledgerAccount, null, $from->subDay(), $landlordId) : 0.0;

        $lines = JournalLine::with(['entry', 'landlord'])->where('ledger_account_id', $ledgerAccount->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($from, fn ($q) => $q->whereDate('journal_entries.entry_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('journal_entries.entry_date', '<=', $to->toDateString()))
            ->when($landlordId, fn ($q) => $q->where('journal_lines.landlord_id', $landlordId))
            ->orderBy('journal_entries.entry_date')->orderBy('journal_entries.id')
            ->select('journal_lines.*')->limit(2000)->get();

        $running = $opening;
        foreach ($lines as $line) {
            $delta = (float) $line->debit - (float) $line->credit;
            $running += $ledgerAccount->isDebitNormal() ? $delta : -$delta;
            $line->running = round($running, 2);
        }

        $perLandlord = $ledgerAccount->system_key === 'landlord_payable'
            ? $this->ledger->landlordPayables()->filter(fn ($v) => abs($v) >= 0.01)->sortDesc()
            : collect();

        return view('staff.books.ledger', [
            'account' => $ledgerAccount,
            'period' => $period, 'from' => $from, 'to' => $to,
            'opening' => $opening,
            'lines' => $lines,
            'closing' => $running,
            'perLandlord' => $perLandlord,
            'landlordNames' => Landlord::whereIn('id', $perLandlord->keys()->push($landlordId)->filter())->pluck('name', 'id'),
            'landlordId' => $landlordId,
        ]);
    }

    // --- Journal ----------------------------------------------------------------

    public function journal(Request $request)
    {
        [$period, $from, $to] = $this->range($request, 'this_month');

        $entries = JournalEntry::with(['lines.account', 'lines.landlord', 'createdBy'])
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to->toDateString()))
            ->when($request->input('source'), fn ($q, $s) => $s === 'manual' ? $q->where('source_type', 'manual') : $q->where('source_type', $s))
            ->when(trim((string) $request->input('search')), fn ($q, $s) => $q->where(fn ($q) => $q->where('description', 'like', "%{$s}%")->orWhere('number', 'like', "%{$s}%")))
            ->latest('entry_date')->latest('id')->paginate(30)->withQueryString();

        return view('staff.books.journal', [
            'entries' => $entries, 'period' => $period, 'from' => $from, 'to' => $to,
            'allAccounts' => LedgerAccount::where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function storeJournal(Request $request)
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:300'],
            'lines' => ['required', 'array', 'min:2', 'max:20'],
            'lines.*.account_id' => ['required', 'integer', 'exists:ledger_accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:200'],
        ]);

        $payable = LedgerAccount::key('landlord_payable')->id;
        $lines = collect($data['lines'])->map(fn ($l) => [
            'account' => (int) $l['account_id'], 'debit' => $l['debit'] ?? 0, 'credit' => $l['credit'] ?? 0, 'memo' => $l['memo'] ?? null,
        ]);
        if ($lines->contains(fn ($l) => $l['account'] === $payable)) {
            return back()->withInput()->with('error', 'Landlord funds are only changed by collections and payouts, not manual journals.');
        }

        try {
            $entry = $this->ledger->post($data['entry_date'], $data['description'], $lines->all(), 'manual', null, null, $request->user('staff'));
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Journal {$entry->number} posted.");
    }

    public function storeExpense(Request $request)
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'expense_account_id' => ['required', Rule::exists('ledger_accounts', 'id')->where('type', 'expense')],
            'paid_from_id' => ['required', Rule::exists('ledger_accounts', 'id')->where('type', 'asset')],
            'amount' => ['required', 'numeric', 'min:1'],
            'description' => ['required', 'string', 'max:300'],
        ]);

        $entry = $this->ledger->post($data['entry_date'], $data['description'], [
            ['account' => (int) $data['expense_account_id'], 'debit' => $data['amount']],
            ['account' => (int) $data['paid_from_id'], 'credit' => $data['amount']],
        ], 'manual', null, null, $request->user('staff'));

        return back()->with('success', "Expense recorded ({$entry->number}).");
    }

    public function storeTransfer(Request $request)
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'from_id' => ['required', Rule::exists('ledger_accounts', 'id')->where('type', 'asset')],
            'to_id' => ['required', 'different:from_id', Rule::exists('ledger_accounts', 'id')->where('type', 'asset')],
            'amount' => ['required', 'numeric', 'min:1'],
            'description' => ['nullable', 'string', 'max:300'],
        ], ['to_id.different' => 'Choose two different accounts.']);

        $from = LedgerAccount::find($data['from_id']);
        $to = LedgerAccount::find($data['to_id']);
        $entry = $this->ledger->post($data['entry_date'], ($data['description'] ?? null) ?: "Transfer from {$from->name} to {$to->name}", [
            ['account' => $to, 'debit' => $data['amount']],
            ['account' => $from, 'credit' => $data['amount']],
        ], 'manual', null, null, $request->user('staff'));

        return back()->with('success', "Transfer recorded ({$entry->number}).");
    }

    /** Manual entries can be reversed; automatic ones are corrected at their source. */
    public function reverse(Request $request, JournalEntry $journalEntry)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        if (!$journalEntry->isManual()) {
            return back()->with('error', 'Automatic entries are corrected where they came from (e.g. edit the SMS purchase), not reversed here.');
        }

        try {
            $reversal = $this->ledger->reverse($journalEntry, $request->user('staff'), $data['reason']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$journalEntry->number} reversed by {$reversal->number}.");
    }

    // --- Reports ----------------------------------------------------------------

    public function profitAndLoss(Request $request, FinancialReports $reports)
    {
        [$period, $from, $to] = $this->range($request, 'this_month');
        $from ??= CarbonImmutable::parse(JournalEntry::min('entry_date') ?? now())->startOfDay();
        $to ??= CarbonImmutable::now();

        return $this->respond($request, $reports->profitAndLoss($from, $to), compact('period', 'from', 'to'));
    }

    public function trialBalance(Request $request, FinancialReports $reports)
    {
        $asAt = $this->asAt($request);

        return $this->respond($request, $reports->trialBalance($asAt), ['asAt' => $asAt]);
    }

    public function balanceSheet(Request $request, FinancialReports $reports)
    {
        $asAt = $this->asAt($request);
        $compareAt = $request->filled('compare')
            ? CarbonImmutable::parse($request->input('compare'))->endOfDay()
            : $asAt->subMonthNoOverflow()->endOfMonth();

        return $this->respond($request, $reports->balanceSheet($asAt, $compareAt), ['asAt' => $asAt, 'compareAt' => $compareAt]);
    }

    /** The report on screen, or as a PDF / Excel download (?format=pdf|xlsx). */
    protected function respond(Request $request, Report $report, array $filters)
    {
        return match ($request->input('format')) {
            'pdf' => app(ReportPdf::class)->make($report)->stream($report->filename('pdf')),
            'xlsx' => app(ReportSpreadsheet::class)->download($report),
            default => view('staff.books.report', ['report' => $report] + $filters),
        };
    }

    protected function asAt(Request $request): CarbonImmutable
    {
        $request->validate(['as_at' => ['nullable', 'date'], 'compare' => ['nullable', 'date']]);

        return ($request->filled('as_at') ? CarbonImmutable::parse($request->input('as_at')) : CarbonImmutable::now())->endOfDay();
    }

    // ------------------------------------------------------------------------------

    /** @return array{0: string, 1: ?CarbonImmutable, 2: ?CarbonImmutable} */
    protected function range(Request $request, string $default): array
    {
        $now = CarbonImmutable::now();
        $period = array_key_exists((string) $request->input('period'), self::PERIODS) ? $request->input('period') : ($request->filled('from') ? 'custom' : $default);

        [$from, $to] = match ($period) {
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'this_quarter' => [$now->startOfQuarter(), $now],
            'this_year' => [$now->startOfYear(), $now],
            'last_12' => [$now->subMonthsNoOverflow(11)->startOfMonth(), $now],
            'all' => [null, null],
            'custom' => [
                $request->filled('from') ? CarbonImmutable::parse($request->input('from')) : null,
                $request->filled('to') ? CarbonImmutable::parse($request->input('to')) : $now,
            ],
            default => [$now->startOfMonth(), $now],
        };

        return [$period, $from?->startOfDay(), $to?->endOfDay()];
    }

    /** Should always be 0: total debits minus total credits across all entries. */
    protected function unbalancedTotal(): float
    {
        $row = JournalLine::selectRaw('COALESCE(SUM(debit), 0) as dr, COALESCE(SUM(credit), 0) as cr')->first();

        return round((float) $row->dr - (float) $row->cr, 2);
    }
}
