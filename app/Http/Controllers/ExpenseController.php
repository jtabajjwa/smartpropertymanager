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

    public function store(StoreExpenseRequest $request, SecurityDeposits $deposits)
    {
        $data = $request->validated();
        $fromDeposit = $request->boolean('paid_from_deposit');
        unset($data['paid_from_deposit'], $data['deposit_lease_id'], $data['deposit_category']);

        try {
            $remaining = DB::transaction(function () use ($data, $fromDeposit, $request, $deposits) {
                $expense = Expense::create($data);
                $expense->update(['number' => app(NumberGenerator::class)->next(CurrentLandlord::id(), 'expense', 'EXP')]);

                // Paid with a tenant's security deposit: the deposit goes down by the same amount.
                if ($fromDeposit) {
                    $lease = \App\Models\Lease::with('tenant')->findOrFail($request->deposit_lease_id);
                    $result = $deposits->deduct($lease, (string) $expense->amount, $expense->date->toDateString(), $request->deposit_category, $data['notes'] ?? null, expense: $expense);

                    return [$result['entry'], $result['remaining']];
                }

                return null;
            });
        } catch (DepositException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        if ($remaining) {
            app(\App\Services\SmsNotificationService::class)->depositDeduction($remaining[0]->load('lease.tenant'), $remaining[1]);

            return redirect()->back()->with('success', 'Expense recorded and paid from '.$remaining[0]->lease->tenant?->name.'\'s security deposit (UGX '.number_format($remaining[1]).' left).');
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
