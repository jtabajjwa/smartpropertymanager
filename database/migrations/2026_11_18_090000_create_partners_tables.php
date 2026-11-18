<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing partners and agents (revenue share), phase 1: partners and their
 * sign-in, codes (with a short agent number), promotions that discount
 * subscriptions, and which partner brought each landlord in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 300)->nullable();
            $table->string('discount_type', 10);              // percent | fixed
            $table->decimal('discount_value', 12, 2);
            $table->unsignedSmallInteger('periods')->default(1); // paid billing periods the discount applies to
            $table->json('plan_ids')->nullable();             // null = every plan
            $table->json('cycles')->nullable();               // null = every billing cycle
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('max_landlords')->nullable(); // how many landlords may use it
            $table->string('funded_by', 10)->default('primora'); // primora | partner | split
            $table->decimal('partner_share_percent', 5, 2)->default(0); // for "split"
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('number', 10)->unique();           // the agent number, e.g. 4821
            $table->string('type', 20)->default('agent');     // agent | marketing
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('id_type', 30)->nullable();
            $table->string('id_number', 60)->nullable();
            $table->string('tin', 30)->nullable();
            $table->string('address', 300)->nullable();
            $table->text('about')->nullable();                // how they plan to find landlords (application)
            $table->string('status', 20)->default('pending'); // pending | active | suspended | rejected
            $table->string('status_note', 300)->nullable();
            $table->foreignId('default_promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->timestamp('agreement_accepted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_staff_user_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('partner_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 30)->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('partner_password_reset_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10);
            $table->boolean('used')->default(false);
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('partner_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->nullable()->constrained()->cascadeOnDelete(); // null = a Primora campaign code
            $table->string('code', 30)->unique();
            $table->string('label', 120)->nullable();          // the campaign, e.g. "Kampala expo"
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->boolean('is_default')->default(false);     // the code the agent number stands for
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('landlords', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->after('plan_id')->constrained()->nullOnDelete();
            $table->foreignId('partner_code_id')->nullable()->after('partner_id')->constrained()->nullOnDelete();
            $table->foreignId('promotion_id')->nullable()->after('partner_code_id')->constrained()->nullOnDelete();
            $table->string('partner_source', 20)->nullable()->after('promotion_id'); // code | partner | staff
            $table->timestamp('partner_attributed_at')->nullable()->after('partner_source');
            $table->timestamp('partner_locked_at')->nullable()->after('partner_attributed_at'); // first paid subscription
            $table->unsignedSmallInteger('promotion_periods_used')->default(0)->after('partner_locked_at');
        });

        Schema::create('promotion_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained();
            $table->foreignId('landlord_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('partner_code_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_transaction_id')->unique()->constrained();
            $table->unsignedSmallInteger('period_number');
            $table->decimal('list_amount', 12, 2);
            $table->decimal('discount_amount', 12, 2);
            $table->decimal('primora_share', 12, 2);
            $table->decimal('partner_share', 12, 2);
            $table->timestamps();
        });

        // Partner sub-ledger on journal lines (like landlord_id for landlord funds).
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->after('landlord_id')->constrained()->nullOnDelete();
        });

        // Accounts the app posts to for partners and promotions.
        $now = now();
        $accounts = [
            ['2100', 'Partner commissions payable', 'liability', 'current_liability', 'partner_payable', 'Revenue share owed to marketing partners and agents, less discounts they fund.'],
            ['5300', 'Promotional discounts', 'expense', 'operating_expense', 'promo_discounts', 'Subscription discounts given through promotions and partner codes.'],
            ['5310', 'Partner commissions', 'expense', 'operating_expense', 'partner_commissions', 'Revenue share earned by marketing partners and agents.'],
        ];
        foreach ($accounts as [$code, $name, $type, $category, $key, $description]) {
            if (DB::table('ledger_accounts')->where('system_key', $key)->exists()) {
                continue;
            }
            while (DB::table('ledger_accounts')->where('code', $code)->exists()) {
                $code = (string) ((int) $code + 1);   // a staff-added account already has this code
            }
            DB::table('ledger_accounts')->insert([
                'code' => $code, 'name' => $name, 'type' => $type, 'category' => $category, 'system_key' => $key,
                'description' => $description, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('ledger_accounts')->whereIn('system_key', ['partner_payable', 'promo_discounts', 'partner_commissions'])
            ->whereNotExists(fn ($q) => $q->from('journal_lines')->whereColumn('journal_lines.ledger_account_id', 'ledger_accounts.id'))->delete();
        Schema::table('journal_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('partner_id'));
        Schema::dropIfExists('promotion_redemptions');
        Schema::table('landlords', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropConstrainedForeignId('partner_code_id');
            $table->dropConstrainedForeignId('partner_id');
            $table->dropColumn(['partner_source', 'partner_attributed_at', 'partner_locked_at', 'promotion_periods_used']);
        });
        Schema::dropIfExists('partner_codes');
        Schema::dropIfExists('partner_password_reset_codes');
        Schema::dropIfExists('partner_users');
        Schema::dropIfExists('partners');
        Schema::dropIfExists('promotions');
    }
};
