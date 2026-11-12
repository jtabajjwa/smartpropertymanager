<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Lease;
use App\Models\SecurityDepositLedger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Spending a tenant's security deposit. A deduction can pay for a cost the
 * landlord had (a broken window fixed, cleaning): then it is also an expense,
 * and the two are linked so the deposit, the expenses list and the books
 * agree, and changing or deleting one changes the other.
 */
class SecurityDeposits
{
    /** What is left of the lease's deposit (locked when $lock, inside a transaction). */
    public function balance(int $leaseId, bool $lock = false): string
    {
        $entries = SecurityDepositLedger::where('lease_id', $leaseId)->when($lock, fn ($q) => $q->lockForUpdate())->get(['type', 'amount']);
        $in = (string) $entries->where('type', 'deposit')->sum('amount');
        $out = (string) $entries->where('type', '!=', 'deposit')->sum('amount');

        return bcsub($in, $out, 2);
    }

    /**
     * Take $amount from the lease's deposit for $category. With $expenseItemId
     * the cost is also recorded as an expense on the lease's unit.
     *
     * @return array{entry: SecurityDepositLedger, remaining: float}
     *
     * @throws DepositException when the deposit can't cover it
     */
    public function deduct(Lease $lease, string $amount, string $date, string $category, ?string $notes = null, ?UploadedFile $attachment = null, ?int $expenseItemId = null, ?Expense $expense = null): array
    {
        $path = $attachment?->store('deposits/receipts', 'public');

        try {
            return DB::transaction(function () use ($lease, $amount, $date, $category, $notes, $path, $expenseItemId, $expense) {
                $balance = $this->balance($lease->id, true);
                if (bccomp($amount, $balance, 2) > 0) {
                    throw new DepositException('UGX '.number_format((float) $amount).' is more than the deposit left for '.($lease->tenant?->name ?? 'this tenant').' (UGX '.number_format((float) $balance).').');
                }

                if (!$expense && $expenseItemId) {
                    $lease->loadMissing('unit');
                    $expense = Expense::create([
                        'date' => $date, 'expense_item_id' => $expenseItemId, 'estate_id' => $lease->unit?->estate_id, 'unit_id' => $lease->unit_id,
                        'amount' => $amount, 'notes' => trim(($notes ?: SecurityDepositLedger::CATEGORIES[$category] ?? 'Deposit deduction').' (paid from '.($lease->tenant?->name ?? 'the tenant').'\'s security deposit)'),
                    ]);
                    $expense->update(['number' => app(NumberGenerator::class)->next($lease->landlord_id, 'expense', 'EXP')]);
                }

                $entry = SecurityDepositLedger::create([
                    'lease_id' => $lease->id, 'type' => 'deduction', 'category' => $category, 'date' => $date, 'amount' => $amount,
                    'notes' => $notes, 'attachment' => $path, 'expense_id' => $expense?->id,
                ]);

                return ['entry' => $entry, 'remaining' => (float) bcsub($balance, $amount, 2)];
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
            throw $e;
        }
    }

    /** A linked expense's amount or date changed: the deduction follows (if the deposit allows). */
    public function syncFromExpense(Expense $expense): void
    {
        $entry = $expense->depositDeduction()->first();
        if (!$entry) {
            return;
        }

        DB::transaction(function () use ($expense, $entry) {
            $available = bcadd($this->balance($entry->lease_id, true), (string) $entry->amount, 2);
            if (bccomp((string) $expense->amount, $available, 2) > 0) {
                throw new DepositException('This expense was paid from a security deposit that only has UGX '.number_format((float) $available).' for it.');
            }
            $entry->update(['amount' => $expense->amount, 'date' => $expense->date]);
        });
    }

    /** A deduction's amount or date changed: its expense follows. */
    public function syncToExpense(SecurityDepositLedger $entry): void
    {
        $entry->expense?->update(['amount' => $entry->amount, 'date' => $entry->date]);
    }

    /** Delete a deduction with its expense (or an expense with its deduction): they are one event. */
    public function deleteTogether(?SecurityDepositLedger $entry, ?Expense $expense): void
    {
        DB::transaction(function () use ($entry, $expense) {
            $entry ??= $expense?->depositDeduction()->first();
            $expense ??= $entry?->expense;

            if ($entry) {
                $attachment = $entry->attachment;
                $entry->delete();
                if ($attachment) {
                    DB::afterCommit(fn () => Storage::disk('public')->delete($attachment));
                }
            }
            $expense?->delete();
        });
    }

    /**
     * Leases whose deposit still has money, for choosing which deposit pays an
     * expense: [lease id => "Grace Namata · Kisaasi A1 · UGX 400,000 left"].
     *
     * @return Collection<int, array{label: string, balance: float, estate_id: ?int, unit_id: ?int}>
     */
    public function withBalance(): Collection
    {
        $ids = SecurityDepositLedger::query()->select('lease_id')->groupBy('lease_id')
            ->havingRaw("SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END) > 0")->pluck('lease_id');

        return Lease::with(['tenant:id,name', 'unit.estate:id,name'])->whereIn('id', $ids)->get()
            ->mapWithKeys(function (Lease $lease) {
                $balance = (float) $this->balance($lease->id);

                return [$lease->id => [
                    'label' => trim(($lease->tenant?->name ?? 'Tenant').' · '.trim(($lease->unit?->estate?->name ?? '').' '.($lease->unit?->name ?? ''))).' · UGX '.number_format($balance).' left',
                    'balance' => $balance,
                    'estate_id' => $lease->unit?->estate_id,
                    'unit_id' => $lease->unit_id,
                ]];
            })
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE);
    }
}
