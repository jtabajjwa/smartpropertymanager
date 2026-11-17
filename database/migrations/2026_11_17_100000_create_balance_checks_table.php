<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic reconciliation, level 2: what a partner (Yo! today) says it holds
 * for us, next to what the books say, plus the safeguarding check that the
 * money held covers what is owed to landlords.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts');
            $table->string('gateway', 30);
            $table->timestamp('checked_at')->index();
            $table->string('status', 20); // matched | difference | failed
            $table->decimal('partner_balance', 15, 2)->nullable();
            $table->decimal('book_balance', 15, 2);
            $table->decimal('in_flight', 15, 2)->default(0);   // payouts sent but not yet completed in the books
            $table->decimal('difference', 15, 2)->nullable();  // partner − (books − in flight)
            $table->json('wallets')->nullable();
            $table->decimal('money_held', 15, 2)->nullable();
            $table->decimal('landlord_funds', 15, 2);
            $table->string('message', 500)->nullable();
            $table->json('problems')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->foreignId('checked_by_staff_user_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_checks');
    }
};
