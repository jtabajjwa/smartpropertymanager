<?php

namespace App\Accounting;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\StaffUser;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Primora's double-entry books. Every entry balances (debits = credits);
 * an entry caused by something in the app (a payment, a payout…) is keyed
 * by its source so it is posted exactly once. Mistakes are corrected by
 * reversing an entry, never by editing or deleting it.
 */
class Ledger
{
    /** @var array<string, LedgerAccount> */
    protected array $accounts = [];

    public function account(LedgerAccount|string|int $account): LedgerAccount
    {
        if ($account instanceof LedgerAccount) {
            return $account;
        }
        if (is_int($account)) {
            return LedgerAccount::findOrFail($account);
        }

        return $this->accounts[$account] ??= LedgerAccount::key($account);
    }

    /**
     * Post a balanced entry.
     *
     * @param  array<int, array{account: LedgerAccount|string|int, debit?: float|int|string, credit?: float|int|string, landlord_id?: ?int, memo?: ?string}>  $lines
     *
     * @throws \InvalidArgumentException when the lines don't balance or are empty
     */
    public function post(
        CarbonInterface|string $date,
        string $description,
        array $lines,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $sourceEvent = null,
        ?StaffUser $by = null,
        ?int $reverses = null,
    ): JournalEntry {
        if ($sourceType && $sourceEvent) {
            $existing = JournalEntry::where('source_type', $sourceType)->where('source_id', $sourceId)->where('source_event', $sourceEvent)->first();
            if ($existing) {
                return $existing;
            }
        }

        $prepared = [];
        $debits = '0';
        $credits = '0';
        foreach ($lines as $line) {
            $debit = number_format((float) ($line['debit'] ?? 0), 2, '.', '');
            $credit = number_format((float) ($line['credit'] ?? 0), 2, '.', '');
            if (bccomp($debit, '0', 2) === 0 && bccomp($credit, '0', 2) === 0) {
                continue;
            }
            if (bccomp($debit, '0', 2) < 0 || bccomp($credit, '0', 2) < 0) {
                throw new \InvalidArgumentException('Journal amounts cannot be negative.');
            }
            $prepared[] = [
                'ledger_account_id' => $this->account($line['account'])->id,
                'debit' => $debit,
                'credit' => $credit,
                'landlord_id' => $line['landlord_id'] ?? null,
                'memo' => isset($line['memo']) ? mb_substr((string) $line['memo'], 0, 200) : null,
            ];
            $debits = bcadd($debits, $debit, 2);
            $credits = bcadd($credits, $credit, 2);
        }

        if (!$prepared || bccomp($debits, '0', 2) === 0) {
            throw new \InvalidArgumentException('A journal entry needs at least one amount.');
        }
        if (bccomp($debits, $credits, 2) !== 0) {
            throw new \InvalidArgumentException("Journal entry does not balance: debits {$debits}, credits {$credits}.");
        }

        return DB::transaction(function () use ($date, $description, $prepared, $sourceType, $sourceId, $sourceEvent, $by, $reverses) {
            $entry = JournalEntry::create([
                'entry_date' => $date instanceof CarbonInterface ? $date->toDateString() : $date,
                'description' => mb_substr($description, 0, 300),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_event' => $sourceEvent,
                'created_by_staff_user_id' => $by?->id,
                'reverses_entry_id' => $reverses,
            ]);
            $entry->update(['number' => 'JE-'.str_pad((string) $entry->id, 6, '0', STR_PAD_LEFT)]);
            $entry->lines()->createMany($prepared);

            return $entry->load('lines');
        });
    }

    /** Cancel an entry's effect with an equal and opposite one (the original stays, marked reversed). */
    public function reverse(JournalEntry $entry, ?StaffUser $by, string $reason, CarbonInterface|string|null $date = null): JournalEntry
    {
        if ($entry->reversed_at) {
            throw new \InvalidArgumentException("{$entry->number} has already been reversed.");
        }
        if ($entry->reverses_entry_id) {
            throw new \InvalidArgumentException("{$entry->number} is itself a reversal.");
        }

        return DB::transaction(function () use ($entry, $by, $reason, $date) {
            $lines = $entry->lines->map(fn (JournalLine $l) => [
                'account' => (int) $l->ledger_account_id, 'debit' => $l->credit, 'credit' => $l->debit,
                'landlord_id' => $l->landlord_id, 'memo' => $l->memo,
            ])->all();

            $reversal = $this->post(
                $date ?? now(), "Reversal of {$entry->number}: {$reason}", $lines,
                $entry->source_type, $entry->source_id, 'reversal-of-'.$entry->id, $by, $entry->id,
            );
            $entry->update(['reversed_at' => now()]);

            return $reversal;
        });
    }

    /**
     * An account's balance on its normal side (assets/expenses: debit − credit;
     * liabilities/equity/income: credit − debit), optionally for a period or one landlord.
     */
    public function balance(LedgerAccount|string|int $account, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $landlordId = null): float
    {
        $account = $this->account($account);
        $row = $this->lines($from, $to)->where('journal_lines.ledger_account_id', $account->id)
            ->when($landlordId, fn ($q) => $q->where('journal_lines.landlord_id', $landlordId))
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as dr, COALESCE(SUM(journal_lines.credit), 0) as cr')->first();

        $net = (float) $row->dr - (float) $row->cr;

        return round($account->isDebitNormal() ? $net : -$net, 2);
    }

    /** Every account with its balance (and period debits/credits). */
    public function balances(?CarbonInterface $from = null, ?CarbonInterface $to = null): Collection
    {
        $sums = $this->lines($from, $to)
            ->selectRaw('journal_lines.ledger_account_id as id, SUM(journal_lines.debit) as dr, SUM(journal_lines.credit) as cr')
            ->groupBy('journal_lines.ledger_account_id')->get()->keyBy('id');

        return LedgerAccount::orderBy('code')->get()->each(function (LedgerAccount $a) use ($sums) {
            $dr = (float) ($sums[$a->id]->dr ?? 0);
            $cr = (float) ($sums[$a->id]->cr ?? 0);
            $a->period_debit = $dr;
            $a->period_credit = $cr;
            $a->balance = round($a->isDebitNormal() ? $dr - $cr : $cr - $dr, 2);
        });
    }

    /** Income and expenses for a period, and the profit. */
    public function profitAndLoss(CarbonInterface $from, CarbonInterface $to): array
    {
        $accounts = $this->balances($from, $to);
        $income = $accounts->where('type', 'income')->filter(fn ($a) => $a->balance != 0 || $a->system_key)->values();
        $expense = $accounts->where('type', 'expense')->filter(fn ($a) => $a->balance != 0 || $a->system_key)->values();

        return [
            'income' => $income,
            'expense' => $expense,
            'total_income' => round($income->sum('balance'), 2),
            'total_expense' => round($expense->sum('balance'), 2),
            'profit' => round($income->sum('balance') - $expense->sum('balance'), 2),
        ];
    }

    /** What Primora owes each landlord (landlord_id => amount). */
    public function landlordPayables(): Collection
    {
        $account = $this->account('landlord_payable');

        return JournalLine::where('ledger_account_id', $account->id)->whereNotNull('landlord_id')
            ->selectRaw('landlord_id, SUM(credit) - SUM(debit) as owed')->groupBy('landlord_id')
            ->pluck('owed', 'landlord_id')->map(fn ($v) => round((float) $v, 2));
    }

    public function landlordPayable(int $landlordId): float
    {
        return $this->balance('landlord_payable', landlordId: $landlordId);
    }

    protected function lines(?CarbonInterface $from, ?CarbonInterface $to)
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($from, fn ($q) => $q->whereDate('journal_entries.entry_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('journal_entries.entry_date', '<=', $to->toDateString()));
    }
}
