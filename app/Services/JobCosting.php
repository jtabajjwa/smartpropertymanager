<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Unit;
use App\Models\VendorJob;
use Illuminate\Validation\ValidationException;

/**
 * What a job card's cost becomes in the books.
 *
 * A completed job is recorded one of three ways ("record"):
 *   expense   - a paid expense is created (or kept up to date) for the cost
 *   existing  - the cost was already recorded; link that expense instead
 *   none      - no charge (warranty, goodwill)
 *
 * Every landlord records a completed job as a paid expense for now, which is
 * right on cash books and on accrual books when the vendor is paid on
 * completion. Accrual books will later get vendor bills: completing a job
 * then raises a bill (money owed to the vendor) and paying it records the
 * payment. That slots in here as another "record" option; callers don't change.
 *
 * Runs in the landlord's scope (CurrentLandlord), inside the caller's transaction.
 */
class JobCosting
{
    public const RECORD_OPTIONS = [
        'expense' => 'Record it as an expense',
        'existing' => "It's already recorded as an expense",
        'none' => 'No charge',
    ];

    /**
     * Bring the job's expense in line after a create/update.
     *
     * @param  array{record?: string, expense_item_id?: int, paid_on?: string, expense_id?: int, remove_expense?: bool}  $costing
     * @return string|null  a note for the user about what happened to the books
     */
    public function sync(VendorJob $job, bool $wasCompleted, array $costing): ?string
    {
        if ($job->status !== 'completed') {
            return $wasCompleted ? $this->reopen($job, !empty($costing['remove_expense'])) : null;
        }

        $job->completed_on ??= today();

        $note = match ($costing['record'] ?? 'expense') {
            'none' => $this->noCharge($job),
            'existing' => $this->linkExisting($job, (int) ($costing['expense_id'] ?? 0)),
            default => $this->recordExpense($job, $costing),
        };
        $job->save();

        return $note;
    }

    protected function recordExpense(VendorJob $job, array $costing): ?string
    {
        if ((float) $job->cost <= 0) {
            throw ValidationException::withMessages(['cost' => 'Enter what the job cost, or choose "No charge".']);
        }
        $estateId = $job->estate_id ?? ($job->unit_id ? Unit::whereKey($job->unit_id)->value('estate_id') : null);
        if (!$estateId) {
            throw ValidationException::withMessages(['estate_id' => 'Choose the estate this job was for, so the expense can be recorded against it.']);
        }

        $fields = [
            'date' => $costing['paid_on'] ?? today()->toDateString(),
            'expense_item_id' => $costing['expense_item_id'] ?? null,
            'estate_id' => $estateId,
            'unit_id' => $job->unit_id,
            'amount' => $job->cost,
            'notes' => trim("{$job->number} · {$job->vendor?->name} · {$job->title}", ' ·'),
        ];

        $job->no_charge = false;
        if ($expense = $job->expense) {
            // Already linked: keep it in step with the job, but leave its own notes alone.
            unset($fields['notes']);
            $fields['expense_item_id'] ??= $expense->expense_item_id;
            $expense->update($fields);

            return null;
        }
        if (empty($fields['expense_item_id'])) {
            throw ValidationException::withMessages(['expense_item_id' => 'Choose the expense item to record this job under.']);
        }

        $expense = Expense::create($fields);
        $expense->update(['number' => app(NumberGenerator::class)->next(CurrentLandlord::id(), 'expense', 'EXP')]);
        $job->expense()->associate($expense);

        return "Expense {$expense->number} recorded for UGX ".number_format((float) $job->cost).'.';
    }

    protected function linkExisting(VendorJob $job, int $expenseId): ?string
    {
        $expense = Expense::find($expenseId);
        if (!$expense) {
            throw ValidationException::withMessages(['expense_id' => 'Choose the expense this job was recorded as.']);
        }
        if (VendorJob::where('expense_id', $expense->id)->whereKeyNot($job->id)->exists()) {
            throw ValidationException::withMessages(['expense_id' => "Expense {$expense->number} is already linked to another job card."]);
        }

        $previous = $job->expense_id && $job->expense_id !== $expense->id ? $job->expense : null;
        $job->no_charge = false;
        $job->expense()->associate($expense);

        return $previous ? "Linked to {$expense->number}. Expense {$previous->number} was kept; delete it on the Expenses page if it's no longer needed." : null;
    }

    protected function noCharge(VendorJob $job): ?string
    {
        $previous = $job->expense;
        $job->no_charge = true;
        $job->expense()->dissociate();

        return $previous ? "Marked as no charge. Expense {$previous->number} was kept; delete it on the Expenses page if it's no longer needed." : null;
    }

    /** Back from completed (reopened or cancelled): optionally delete the job's expense. */
    protected function reopen(VendorJob $job, bool $removeExpense): ?string
    {
        $expense = $job->expense;
        $job->completed_on = null;
        $job->no_charge = false;
        if ($expense && $removeExpense) {
            $job->expense()->dissociate();
            $job->save();
            $expense->delete();

            return "Expense {$expense->number} was deleted.";
        }
        $job->save();

        return $expense ? "Expense {$expense->number} is still recorded." : null;
    }
}
