<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Liquidations (paying landlords the rent Primora collected for them) and a
 * small double-entry ledger for Primora's own books.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Where a landlord can be paid. Staff verify each one before it can be used.
        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                     // mobile_money | bank
            $table->string('account_name', 150);            // registered name on the wallet / bank account
            $table->string('msisdn', 20)->nullable();       // mobile money: 2567XXXXXXXX
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_branch', 100)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('swift_code', 20)->nullable();
            $table->string('status', 20)->default('pending'); // pending | verified | rejected | disabled
            $table->string('review_note', 300)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_staff_user_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['landlord_id', 'status']);
        });

        Schema::create('liquidations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();      // LIQ-261030-K3QX7M
            $table->foreignId('landlord_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payout_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 20);                   // mobile_money | bank
            $table->json('destination');                    // snapshot of the payout account when requested
            $table->decimal('amount', 14, 2);               // taken from the landlord's balance
            $table->decimal('fee', 14, 2)->default(0);      // transfer charge, deducted
            $table->decimal('net_amount', 14, 2);           // what the landlord receives
            $table->string('status', 20)->default('pending'); // pending | approved | processing | completed | failed | rejected | cancelled
            $table->string('request_note', 300)->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_staff_user_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 300)->nullable();
            $table->foreignId('executed_by_staff_user_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Mobile money (Yo! acwithdrawfunds)
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('external_reference', 60)->nullable(); // sent to Yo! for the current attempt
            $table->string('gateway', 30)->nullable();
            $table->string('gateway_reference', 100)->nullable();
            $table->string('network_reference', 150)->nullable();
            $table->string('status_message', 500)->nullable();
            $table->json('last_response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            // Bank (EFT done outside the system)
            $table->string('bank_reference', 100)->nullable();
            $table->date('transfer_date')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_checked_at']);
            $table->index(['landlord_id', 'status']);
        });

        Schema::table('payment_gateway_logs', function (Blueprint $table) {
            $table->foreignId('liquidation_id')->nullable()->after('payment_transaction_id')->constrained()->cascadeOnDelete();
        });

        // Fee bands now serve several purposes: tenant collections and payouts.
        Schema::table('collection_fee_tiers', function (Blueprint $table) {
            $table->string('kind', 30)->default('collection')->after('id'); // collection | payout_mobile_money | payout_bank
        });

        // Which account paid for SMS stock (for the books).
        Schema::table('sms_purchases', function (Blueprint $table) {
            $table->string('paid_from', 30)->default('bank')->after('total_cost');
        });

        // --- Books --------------------------------------------------------
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->string('type', 20);                     // asset | liability | equity | income | expense
            $table->string('system_key', 40)->nullable()->unique(); // accounts the app posts to automatically
            $table->string('description', 300)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->date('entry_date');
            $table->string('description', 300);
            // What caused it (payment, SMS purchase, liquidation, manual…), so each event posts once.
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_event', 60)->nullable();
            $table->foreignId('created_by_staff_user_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'source_event']);
            $table->index('entry_date');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->foreignId('landlord_id')->nullable()->constrained()->nullOnDelete(); // sub-ledger: money owed to each landlord
            $table->string('memo', 200)->nullable();

            $table->index(['ledger_account_id', 'landlord_id']);
        });

        $now = now();
        $accounts = [
            ['1000', 'Yo! Payments pool', 'asset', 'yo_pool', 'Mobile money collected through Yo! Payments, less withdrawals and Yo! charges.'],
            ['1010', 'Bank account', 'asset', 'bank', "Primora's bank account."],
            ['1020', 'Mobile money wallet', 'asset', 'momo_wallet', 'Money sent directly to Primora\'s own mobile money number.'],
            ['1030', 'Cash on hand', 'asset', 'cash', 'Cash received and not yet banked.'],
            ['2000', 'Landlord funds payable', 'liability', 'landlord_payable', 'Rent collected for landlords and not yet paid out to them. Not Primora\'s money.'],
            ['3000', "Owner's equity", 'equity', 'equity', 'Capital put in by the owners, and opening balances.'],
            ['4000', 'Subscription income', 'income', 'subscription_income', 'Landlord subscriptions.'],
            ['4100', 'SMS sales', 'income', 'sms_income', 'SMS credit sold to landlords.'],
            ['4200', 'Collection fee income', 'income', 'collection_fee_income', 'Mobile money collection fees charged on tenant payments.'],
            ['4300', 'Payout fee income', 'income', 'payout_fee_income', 'Transfer charges deducted from landlord payouts.'],
            ['4900', 'Other income', 'income', 'other_income', null],
            ['5000', 'Payment gateway charges', 'expense', 'gateway_charges', 'Yo! Payments charges on collections and withdrawals.'],
            ['5100', 'Bank charges', 'expense', 'bank_charges', 'Bank fees, including EFT charges.'],
            ['5200', 'SMS purchases', 'expense', 'sms_purchases', 'SMS bought from providers.'],
            ['5900', 'Other operating expenses', 'expense', 'other_expenses', null],
        ];
        DB::table('ledger_accounts')->insert(array_map(fn ($a) => [
            'code' => $a[0], 'name' => $a[1], 'type' => $a[2], 'system_key' => $a[3], 'description' => $a[4],
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], $accounts));
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('ledger_accounts');
        Schema::table('sms_purchases', fn (Blueprint $table) => $table->dropColumn('paid_from'));
        Schema::table('collection_fee_tiers', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::table('payment_gateway_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('liquidation_id');
        });
        Schema::dropIfExists('liquidations');
        Schema::dropIfExists('payout_accounts');
    }
};
