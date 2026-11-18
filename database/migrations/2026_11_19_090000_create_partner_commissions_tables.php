<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partners phase 2: revenue share. Commission plans (set by staff), what each
 * partner earns on their landlords' subscription payments, withholding tax,
 * and partner money in the reconciliation's safeguarding check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('base', 30)->default('paid');            // paid | paid_less_charges
            $table->decimal('rate_percent', 5, 2);                   // share of each payment…
            $table->unsignedSmallInteger('months')->nullable();      // …for this many months from the first payment (null = always)
            $table->decimal('later_rate_percent', 5, 2)->default(0); // …then this (0 = no more)
            $table->decimal('signup_bonus', 12, 2)->default(0);      // one-off, on a landlord's first payment
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->foreignId('commission_plan_id')->nullable()->after('default_promotion_id')->constrained('commission_plans')->nullOnDelete();
        });

        Schema::create('partner_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained();
            $table->foreignId('landlord_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained();
            $table->string('type', 20); // share | bonus | discount | adjustment | reversal
            $table->string('description', 300);
            $table->decimal('base_amount', 12, 2)->nullable();
            $table->decimal('rate_percent', 5, 2)->nullable();
            $table->decimal('gross', 12, 2);          // signed
            $table->decimal('wht_percent', 5, 2)->default(0);
            $table->decimal('wht', 12, 2)->default(0);
            $table->decimal('net', 12, 2);            // what the partner gets (signed)
            $table->timestamp('earned_at');
            $table->timestamp('available_at');        // after the holding period
            $table->foreignId('reverses_id')->nullable()->constrained('partner_commissions')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('liquidation_id')->nullable()->index(); // the payout that paid it (phase 3)
            $table->foreignId('created_by_staff_user_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['payment_transaction_id', 'type']);
            $table->index(['partner_id', 'available_at']);
        });

        Schema::table('balance_checks', function (Blueprint $table) {
            $table->decimal('partner_funds', 15, 2)->default(0)->after('landlord_funds');
        });

        if (!DB::table('ledger_accounts')->where('system_key', 'wht_payable')->exists()) {
            $code = '2200';
            while (DB::table('ledger_accounts')->where('code', $code)->exists()) {
                $code = (string) ((int) $code + 1);
            }
            DB::table('ledger_accounts')->insert([
                'code' => $code, 'name' => 'Withholding tax payable', 'type' => 'liability', 'category' => 'current_liability', 'system_key' => 'wht_payable',
                'description' => 'Tax withheld from partner commissions, to be paid to the tax authority.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('ledger_accounts')->where('system_key', 'wht_payable')
            ->whereNotExists(fn ($q) => $q->from('journal_lines')->whereColumn('journal_lines.ledger_account_id', 'ledger_accounts.id'))->delete();
        Schema::table('balance_checks', fn (Blueprint $table) => $table->dropColumn('partner_funds'));
        Schema::dropIfExists('partner_commissions');
        Schema::table('partners', fn (Blueprint $table) => $table->dropConstrainedForeignId('commission_plan_id'));
        Schema::dropIfExists('commission_plans');
    }
};
