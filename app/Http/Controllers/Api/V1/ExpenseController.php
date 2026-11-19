<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordExpense;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Models\Expense;
use App\Models\ExpenseItems;
use App\Models\SecurityDepositLedger;
use App\Services\DepositException;
use App\Services\SecurityDeposits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Expenses: list with totals, record one (optionally from a tenant's deposit), change or delete one. */
class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('search'));
        $query = Expense::with(['expenseItem', 'estate', 'unit', 'depositDeduction.lease.tenant'])
            ->when($request->input('estate_id'), fn ($q, $id) => $q->where('estate_id', $id))
            ->when($request->input('expense_item_id'), fn ($q, $id) => $q->where('expense_item_id', $id))
            ->when($request->input('date_from'), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->input('date_to'), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('number', 'like', "%{$search}%")->orWhere('notes', 'like', "%{$search}%")
                ->orWhereHas('expenseItem', fn ($i) => $i->where('name', 'like', "%{$search}%"))));

        $total = (float) (clone $query)->sum('amount');
        $expenses = $query->latest('date')->latest('id')->paginate(30);

        return response()->json(['total' => round($total, 2)] + $expenses->through(fn (Expense $e) => self::summary($e))->toArray());
    }

    /** What the form needs: expense types, properties, and tenants whose deposit can pay. */
    public function options(SecurityDeposits $deposits)
    {
        return response()->json([
            'items' => ExpenseItems::orderBy('name')->get(['id', 'name']),
            'estates' => \App\Models\Estate::orderBy('name')->get(['id', 'name']),
            'deposit_leases' => $deposits->withBalance()->map(fn ($v, $id) => ['lease_id' => $id] + $v)->values(),
            'deposit_categories' => collect(SecurityDepositLedger::CATEGORIES)->only(SecurityDepositLedger::EXPENSE_CATEGORIES)
                ->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
        ]);
    }

    public function show(Expense $expense)
    {
        return response()->json(self::summary($expense->load(['expenseItem', 'estate', 'unit', 'depositDeduction.lease.tenant'])));
    }

    public function store(StoreExpenseRequest $request, RecordExpense $record)
    {
        try {
            [$expense, , $remaining] = $record($request->validated());
        } catch (DepositException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return response()->json(self::summary($expense->fresh(['expenseItem', 'estate', 'unit', 'depositDeduction.lease.tenant'])) + ['deposit_left' => $remaining], 201);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense)
    {
        try {
            DB::transaction(function () use ($request, $expense) {
                $expense->update($request->validated());
                app(SecurityDeposits::class)->syncFromExpense($expense);
            });
        } catch (DepositException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return response()->json(self::summary($expense->fresh(['expenseItem', 'estate', 'unit', 'depositDeduction.lease.tenant'])));
    }

    /** Deleting one paid from a deposit puts the money back in the deposit. */
    public function destroy(Expense $expense, SecurityDeposits $deposits)
    {
        $fromDeposit = $expense->depositDeduction()->exists();
        $deposits->deleteTogether(null, $expense);

        return response()->json(['message' => $fromDeposit ? 'Expense deleted, and the amount returned to the tenant\'s security deposit.' : 'Expense deleted.']);
    }

    public static function summary(Expense $e): array
    {
        $deduction = $e->relationLoaded('depositDeduction') ? $e->depositDeduction : null;

        return [
            'id' => $e->id,
            'number' => $e->number,
            'date' => $e->date?->toDateString(),
            'item_id' => $e->expense_item_id,
            'item' => $e->expenseItem?->name,
            'estate_id' => $e->estate_id,
            'estate' => $e->estate?->name,
            'unit_id' => $e->unit_id,
            'unit' => $e->unit?->name,
            'amount' => (float) $e->amount,
            'notes' => $e->notes,
            'from_deposit' => $deduction ? ['tenant' => $deduction->lease?->tenant?->name, 'lease_id' => $deduction->lease_id] : null,
        ];
    }
}
