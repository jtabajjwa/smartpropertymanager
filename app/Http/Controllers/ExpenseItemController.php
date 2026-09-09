<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseItemRequest;
use App\Http\Requests\UpdateExpenseItemRequest;
use App\Models\ExpenseItems;

class ExpenseItemController extends Controller
{
    public function index()
    {
        $expenseItems = ExpenseItems::withCount('expenses')->latest()->paginate(15);

        return view('expense-items.index', compact('expenseItems'));
    }

    public function store(StoreExpenseItemRequest $request)
    {
        ExpenseItems::create($request->validated());

        return redirect()->back()->with('success', 'Expense item created successfully.');
    }

    public function update(UpdateExpenseItemRequest $request, ExpenseItems $expense_item)
    {
        $expense_item->update($request->validated());

        return redirect()->back()->with('success', 'Expense item updated successfully.');
    }

    public function destroy(ExpenseItems $expense_item)
    {
        $expense_item->delete();

        return redirect()->back()->with('success', 'Expense item deleted successfully.');
    }
}
