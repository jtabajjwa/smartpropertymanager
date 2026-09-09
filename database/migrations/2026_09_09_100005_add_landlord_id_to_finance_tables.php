<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'payments', 'payment_allocations', 'security_deposit_ledgers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('landlord_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'payments', 'payment_allocations', 'security_deposit_ledgers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('landlord_id');
            });
        }
    }
};
