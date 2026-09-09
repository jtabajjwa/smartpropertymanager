<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected array $numberedTables = ['tenants', 'leases', 'invoices', 'payments', 'expenses'];

    public function up(): void
    {
        foreach ($this->numberedTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropUnique("{$tableName}_number_unique");
                $table->unique(['landlord_id', 'number']);
            });
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique('tenants_email_unique');
            $table->unique(['landlord_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['landlord_id', 'email']);
            $table->unique('email');
        });

        foreach ($this->numberedTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropUnique(['landlord_id', 'number']);
                $table->unique('number');
            });
        }
    }
};
