<?php

use App\Models\ExpenseItems;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give existing landlords the starter expense items new accounts now get
 * (ExpenseItems::DEFAULTS). Items they already have, by name, are skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('landlords')->orderBy('id')->pluck('id') as $landlordId) {
            ExpenseItems::seedDefaultsFor($landlordId);
        }
    }

    public function down(): void
    {
        // Remove only starter items that were never used.
        DB::table('expense_items')
            ->whereIn('name', array_keys(ExpenseItems::DEFAULTS))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('expenses')->whereColumn('expenses.expense_item_id', 'expense_items.id'))
            ->delete();
    }
};
