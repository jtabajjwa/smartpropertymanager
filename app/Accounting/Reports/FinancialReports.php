<?php

namespace App\Accounting\Reports;

use App\Accounting\Ledger;
use App\Models\LedgerAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Profit & Loss, Trial Balance and Balance Sheet, built from the ledger. */
class FinancialReports
{
    public function __construct(protected Ledger $ledger)
    {
    }

    public function profitAndLoss(CarbonImmutable $from, CarbonImmutable $to): Report
    {
        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;
        [$prevFrom, $prevTo] = [$from->subDays($days), $from->subDay()];

        $now = $this->ledger->balances($from, $to);
        $prev = $this->ledger->balances($prevFrom, $prevTo)->keyBy('id');
        $rows = fn (string $type) => $now->where('type', $type)
            ->filter(fn ($a) => $a->system_key || $a->balance != 0 || ($prev[$a->id]->balance ?? 0) != 0)
            ->map(fn ($a) => $this->row($a, [$a->balance, (float) ($prev[$a->id]->balance ?? 0)]));

        $report = new Report('profit-and-loss', 'Profit & Loss', $from->format('d M Y').' to '.$to->format('d M Y'),
            ['This period', 'Previous period']);
        $income = $this->byCategory($report, 'income', $rows('income'), 'Total income', 'Income');
        $expense = $this->byCategory($report, 'expense', $rows('expense'), 'Total expenses', 'Expenses');
        $net = array_merge($income, array_map(fn ($s) => -$s, $expense));
        $report->total($report->totalValue(['combine' => $net], 0) >= 0 ? 'Net profit' : 'Net loss', $net, true)
            ->note('Previous period: '.$prevFrom->format('d M Y').' to '.$prevTo->format('d M Y').' (same length).')
            ->note('Rent collected for landlords is not income: it is held in Landlord funds payable until paid out.')
            ->note('Yo! Payments charges are estimated from the fee bands.');

        return $report;
    }

    /** Every account's balance as at a date, on its debit or credit side. Debits must equal credits. */
    public function trialBalance(CarbonImmutable $asAt): Report
    {
        $accounts = $this->ledger->balances(null, $asAt);

        $rows = $accounts->filter(fn ($a) => $a->period_debit != 0 || $a->period_credit != 0 || $a->system_key)
            ->map(function ($a) {
                $net = round($a->period_debit - $a->period_credit, 2); // positive = debit balance

                return $this->row($a, [$net > 0 ? $net : 0.0, $net < 0 ? -$net : 0.0], $a->typeLabel().' · '.$a->categoryLabel());
            });

        $report = new Report('trial-balance', 'Trial Balance', 'As at '.$asAt->format('d M Y'), ['Debit', 'Credit']);
        $report->section('accounts', null, $rows->values()->all(), 'Total');

        $dr = $report->sectionTotal('accounts', 0);
        $cr = $report->sectionTotal('accounts', 1);
        $report->check = [abs($dr - $cr) < 0.01 ? 'Debits equal credits' : 'Out of balance by '.number_format(abs($dr - $cr), 2), abs($dr - $cr) < 0.01];
        $report->note('Balances include all entries up to and including '.$asAt->format('d M Y').'.');

        return $report;
    }

    /** What Primora has and who it belongs to, as at a date (with a comparison date). */
    public function balanceSheet(CarbonImmutable $asAt, CarbonImmutable $compareAt): Report
    {
        $now = $this->ledger->balances(null, $asAt);
        $then = $this->ledger->balances(null, $compareAt)->keyBy('id');
        $profitNow = $this->profit($now);
        $profitThen = $this->profit($then->values());

        $rows = fn (string $type) => $now->where('type', $type)
            ->filter(fn ($a) => $a->system_key || $a->balance != 0 || ($then[$a->id]->balance ?? 0) != 0)
            ->map(fn ($a) => $this->row($a, [$a->balance, (float) ($then[$a->id]->balance ?? 0)]));

        $equity = $rows('equity')->push([
            'code' => null, 'name' => 'Profit to date (retained)', 'values' => [$profitNow, $profitThen], 'account_id' => null,
            'note' => 'Income less expenses since the books began', 'category' => 'reserves',
        ]);

        $report = new Report('balance-sheet', 'Balance Sheet', 'As at '.$asAt->format('d M Y'), [$asAt->format('d M Y'), $compareAt->format('d M Y')]);
        $assets = $this->byCategory($report, 'asset', $rows('asset'), 'Total assets', 'Assets: what Primora has');
        $liabilities = $this->byCategory($report, 'liability', $rows('liability'), 'Total liabilities', 'Liabilities: owed to others');
        $equityKeys = $this->byCategory($report, 'equity', $equity, 'Total equity', "Equity: Primora's own");
        $report->total('Total liabilities and equity', $liabilities + $equityKeys, true);

        $gap = $report->totalValue(['combine' => $assets], 0) - $report->totalValue(['combine' => $liabilities + $equityKeys], 0);
        $report->check = [abs($gap) < 0.01 ? 'Assets equal liabilities plus equity' : 'Out of balance by '.number_format(abs($gap), 2), abs($gap) < 0.01];
        $workingCapital = $report->sectionTotal('current_asset', 0) - $report->sectionTotal('current_liability', 0);
        $report->note('Working capital (current assets less current liabilities): '.number_format($workingCapital).'.');
        $report->note('Landlord funds payable is rent collected for landlords and not yet paid out: it is held for them, not Primora\'s money.');

        return $report;
    }

    protected function row(LedgerAccount $a, array $values, ?string $note = null): array
    {
        return [
            'code' => $a->code, 'name' => $a->name.($a->is_active ? '' : ' (inactive)'), 'values' => array_map(fn ($v) => round((float) $v, 2), $values),
            'account_id' => $a->id, 'note' => $note, 'category' => $a->categoryKey(),
        ];
    }

    /**
     * One section per category of a type (only categories with accounts), then
     * the type's total right after them, under the category's name; $heading is the type's
     * heading (used only when the type has no accounts). Returns the section keys, each => 1, for combining in totals.
     *
     * @return array<string, int>
     */
    protected function byCategory(Report $report, string $type, Collection $rows, string $totalLabel, string $heading): array
    {
        $categories = LedgerAccount::CATEGORIES[$type];
        $groups = $rows->groupBy(fn ($r) => isset($categories[$r['category'] ?? '']) ? $r['category'] : array_key_first($categories));

        $keys = [];
        foreach ($categories as $key => [$label]) {
            if (!$groups->has($key)) {
                continue;
            }
            $report->section($key, $label, $groups[$key]->values()->all(), 'Total '.mb_strtolower($label));
            $keys[$key] = 1;
        }
        if (!$keys) {
            $first = array_key_first($categories);
            $report->section($first, $heading, [], null);
            $keys[$first] = 1;
        }
        $report->total($totalLabel, $keys, false, array_key_last($keys));

        return $keys;
    }

    protected function profit(Collection $balances): float
    {
        return round($balances->where('type', 'income')->sum('balance') - $balances->where('type', 'expense')->sum('balance'), 2);
    }
}
