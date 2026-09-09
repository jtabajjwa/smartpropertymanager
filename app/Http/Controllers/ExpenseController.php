<?php

namespace App\Http\Controllers;

use App\Exports\ExpensesExport;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    public function index()
    {
        $query = Expense::with(['expenseItem', 'estate', 'unit']);

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

        return view('expenses.index', compact('expenses'));
    }

    public function export(Request $request)
    {
        return (new ExpensesExport($request))->download('expenses.csv');
    }

    public function store(StoreExpenseRequest $request)
    {
        $data = $request->validated();

        DB::transaction(function () use ($data) {
            $expense = Expense::create($data);
            $expense->update(['number' => 'EXP-' . str_pad($expense->id, 5, '0', STR_PAD_LEFT)]);
        });

        return redirect()->back()->with('success', 'Expense created successfully.');
    }

    public function update(UpdateExpenseRequest $request, Expense $expense)
    {
        $expense->update($request->validated());

        return redirect()->back()->with('success', 'Expense updated successfully.');
    }

    public function destroy(Expense $expense)
    {
        $expense->delete();
        return redirect()->back()->with('success', 'Expense deleted successfully.');
    }
}
