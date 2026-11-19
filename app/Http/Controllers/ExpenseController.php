<?php

namespace App\Http\Controllers;

use App\Exports\ExpensesExport;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Models\Expense;
use App\Services\CurrentLandlord;
use App\Services\DepositException;
use App\Services\NumberGenerator;
use App\Services\SecurityDeposits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    public function index()
    {
        $query = Expense::with(['expenseItem', 'estate', 'unit', 'vendorJob:id,expense_id,number', 'depositDeduction.lease.tenant:id,name']);

        if (request('estate_id')) {
            $query->where('estate_id', request('estate_id'));
        }

        if (request('expense_item_id')) {
            $query->where('expense_item_id', request('expense_item_id'));
        }

        if (request('date_from')) {
            $query->whereDate('date', '>=', request('date_from'));
        }

        if (request('date_to')) {
            $query->whereDate('date', '<=', request('date_to'));
        }

        if (request('search')) {
            $search = request('search');
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhereHas('expenseItem', fn ($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        $expenses = $query->latest()->paginate(15)->withQueryString();

        return view('expenses.index', ['expenses' => $expenses, 'depositLeases' => app(SecurityDeposits::class)->withBalance()]);
    }

    public function export(Request $request)
    {
        return (new ExpensesExport($request))->download('expenses.csv');
    }

    public function store(StoreExpenseRequest $request, \App\Actions\RecordExpense $record)
    {
        try {
            [, $entry, $remaining] = $record($request->validated());
        } catch (DepositException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        if ($entry) {
            return redirect()->back()->with('success', 'Expense recorded and paid from '.$entry->lease->tenant?->name.'\'s security deposit (UGX '.number_format($remaining).' left).');
        }

        return redirect()->back()->with('success', 'Expense created successfully.');
    }

    public function update(UpdateExpenseRequest $request, Expense $expense)
    {
        try {
            DB::transaction(function () use ($request, $expense) {
                $expense->update($request->validated());
                app(SecurityDeposits::class)->syncFromExpense($expense);
            });
        } catch (DepositException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Expense updated successfully.');
    }

    public function destroy(Expense $expense)
    {
        $fromDeposit = $expense->depositDeduction()->exists();
        app(SecurityDeposits::class)->deleteTogether(null, $expense);

        return redirect()->back()->with('success', $fromDeposit ? 'Expense deleted, and the amount returned to the tenant\'s security deposit.' : 'Expense deleted successfully.');
    }
}
