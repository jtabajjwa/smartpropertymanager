<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a deposit deduction was for (a reason, the receipt or photo), and the
 * expense it paid for, so the tenant sees a breakdown and the landlord's books
 * show the repair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_deposit_ledgers', function (Blueprint $table) {
            $table->string('category', 30)->nullable()->after('type');
            $table->string('attachment')->nullable()->after('notes');
            $table->foreignId('expense_id')->nullable()->after('attachment')->constrained('expenses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('security_deposit_ledgers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_id');
            $table->dropColumn(['category', 'attachment']);
        });
    }
};
