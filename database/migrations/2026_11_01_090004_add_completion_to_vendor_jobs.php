<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completing a job card records what it cost (see JobCosting): when it was
 * completed, whether it was free of charge, and at most one job per expense.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_jobs', function (Blueprint $table) {
            $table->date('completed_on')->nullable()->after('status');
            $table->boolean('no_charge')->default(false)->after('cost');
        });

        // One job per expense: where an expense was linked to several jobs, keep the first link.
        $dupes = DB::table('vendor_jobs')->whereNotNull('expense_id')
            ->select('expense_id')->groupBy('expense_id')->havingRaw('COUNT(*) > 1')->pluck('expense_id');
        foreach ($dupes as $expenseId) {
            $keep = DB::table('vendor_jobs')->where('expense_id', $expenseId)->min('id');
            DB::table('vendor_jobs')->where('expense_id', $expenseId)->where('id', '!=', $keep)->update(['expense_id' => null]);
        }

        DB::table('vendor_jobs')->where('status', 'completed')->update(['completed_on' => DB::raw('job_date')]);

        Schema::table('vendor_jobs', function (Blueprint $table) {
            $table->unique('expense_id');
        });
        // The unique index now serves the foreign key; a plain one left by an earlier rollback isn't needed.
        if (Schema::hasIndex('vendor_jobs', 'vendor_jobs_expense_id_index')) {
            Schema::table('vendor_jobs', fn (Blueprint $table) => $table->dropIndex(['expense_id']));
        }
    }

    public function down(): void
    {
        // MariaDB uses the unique index for the expense_id foreign key, so give the key a plain index first.
        if (!Schema::hasIndex('vendor_jobs', 'vendor_jobs_expense_id_index')) {
            Schema::table('vendor_jobs', fn (Blueprint $table) => $table->index('expense_id'));
        }
        Schema::table('vendor_jobs', function (Blueprint $table) {
            $table->dropUnique(['expense_id']);
            $table->dropColumn(['completed_on', 'no_charge']);
        });
    }
};
