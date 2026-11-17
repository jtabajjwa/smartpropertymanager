<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Categories within each account type (current / non-current assets and
 * liabilities, operating income, cost of sales…), so the Balance Sheet and
 * Profit & Loss are grouped the way accountants expect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->string('category', 40)->nullable()->after('type');
        });

        // Every account gets its type's usual category, then the accounts the app posts to get the right one.
        $defaults = ['asset' => 'current_asset', 'liability' => 'current_liability', 'equity' => 'capital', 'income' => 'operating_income', 'expense' => 'operating_expense'];
        foreach ($defaults as $type => $category) {
            DB::table('ledger_accounts')->where('type', $type)->update(['category' => $category]);
        }
        $system = [
            'other_income' => 'other_income',
            'gateway_charges' => 'cost_of_sales',
            'sms_purchases' => 'cost_of_sales',
            'bank_charges' => 'finance_cost',
        ];
        foreach ($system as $key => $category) {
            DB::table('ledger_accounts')->where('system_key', $key)->update(['category' => $category]);
        }
    }

    public function down(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
