<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE security_deposit_ledgers MODIFY COLUMN type ENUM('deposit', 'deduction', 'refund', 'forfeit') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE security_deposit_ledgers MODIFY COLUMN type ENUM('deposit', 'deduction', 'refund') NOT NULL");
    }
};
