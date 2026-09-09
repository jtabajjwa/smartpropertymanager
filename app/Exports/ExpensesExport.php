<?php

namespace App\Exports;

use App\Models\Expense;
use Illuminate\Http\Request;

class ExpensesExport
{
    public function __construct(protected Request $request) {}

    public function download(string $filename)
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Number', 'Date', 'Item', 'Estate', 'Unit', 'Amount', 'Notes']);

            $this->buildQuery()->with(['expenseItem', 'estate', 'unit'])->chunk(500, function ($expenses) use ($handle) {
                foreach ($expenses as $expense) {
                    fputcsv($handle, [
                        $expense->number ?? '',
                        $expense->date->format('d M Y'),
                        $expense->expenseItem?->name ?? '',
                        $expense->estate?->name ?? '',
                        $expense->unit?->name ?? '',
                        $expense->amount,
                        $expense->notes ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    protected function buildQuery()
    {
        $query = Expense::query();

        if ($this->request->filled('estate_id')) {
            $query->where('estate_id', $this->request->estate_id);
        }

        if ($this->request->filled('expense_item_id')) {
            $query->where('expense_item_id', $this->request->expense_item_id);
        }

        if ($this->request->filled('date_from')) {
            $query->whereDate('date', '>=', $this->request->date_from);
        }

        if ($this->request->filled('date_to')) {
            $query->whereDate('date', '<=', $this->request->date_to);
        }

        if ($this->request->filled('search')) {
            $search = $this->request->search;
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhereHas('expenseItem', fn ($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        return $query->latest();
    }
}
