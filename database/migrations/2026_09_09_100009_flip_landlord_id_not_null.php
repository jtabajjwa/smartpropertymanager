<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected array $tables = [
        'locations', 'estates', 'units',
        'tenants', 'customers', 'leases',
        'invoices', 'payments', 'payment_allocations', 'security_deposit_ledgers',
        'expenses', 'expense_items', 'utilities',
        'users', 'sms_logs', 'audits',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['landlord_id']);
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('landlord_id')->nullable(false)->change();
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('landlord_id')->references('id')->on('landlords')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['landlord_id']);
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('landlord_id')->nullable()->change();
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('landlord_id')->references('id')->on('landlords')->nullOnDelete();
            });
        }
    }
};
