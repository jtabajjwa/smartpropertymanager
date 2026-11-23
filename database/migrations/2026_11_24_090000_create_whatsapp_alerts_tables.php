<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp alerts (phase 2 of the WhatsApp bot): each landlord chooses how
 * tenant alerts go: only SMS, WhatsApp first with SMS if it fails, both, or
 * only WhatsApp (tenants who can't get WhatsApp still get SMS). WhatsApp has its own prepaid credit (1 credit = 1 alert),
 * kept exactly like SMS credit: bundles, a ledger with the balance after each
 * change, and landlords.whatsapp_balance as the running total.
 *
 * Every alert, whichever way it went, is a row in sms_logs (channel = sms |
 * whatsapp), so landlords see one notification log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landlords', function (Blueprint $table) {
            $table->integer('whatsapp_balance')->default(0)->after('sms_balance');
            // How tenant alerts go: sms | whatsapp_first | both | whatsapp (see Landlord::ALERT_CHANNELS).
            $table->string('alert_channels', 20)->default('sms')->after('whatsapp_balance');
        });

        Schema::table('tenants', function (Blueprint $table) {
            // Consent: the landlord confirmed the tenant agreed, or the tenant messaged the bot.
            $table->timestamp('whatsapp_opted_in_at')->nullable();
            // The tenant tapped "Stop WhatsApp alerts" (or sent STOP): SMS only until they turn it back on.
            $table->timestamp('whatsapp_opted_out_at')->nullable();
            // WhatsApp said the number can't get messages (e.g. not on WhatsApp): SMS for a while.
            $table->timestamp('whatsapp_failed_at')->nullable();
        });

        Schema::create('whatsapp_bundles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedInteger('quantity');          // WhatsApp alerts
            $table->decimal('price', 12, 2);              // UGX
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('whatsapp_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                   // purchase | starter | usage | refund | adjustment
            $table->integer('quantity');
            $table->integer('balance_after');
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sms_log_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_user_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['landlord_id', 'id']);
            $table->index(['type', 'created_at']);
        });

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->string('channel', 10)->default('sms')->after('phone');
            $table->string('wamid')->nullable()->unique()->after('channel'); // WhatsApp's message id
        });

        $now = now();
        foreach (['whatsapp.starter_credits' => '10', 'whatsapp.low_balance_threshold' => '10'] as $key => $value) {
            DB::table('app_settings')->insertOrIgnore(['key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
        }

        // Primora's books: WhatsApp credit sold, and what Meta charges for the messages.
        $accounts = [
            ['4110', 'WhatsApp sales', 'income', 'operating_income', 'whatsapp_income', 'WhatsApp alert credit sold to landlords.'],
            ['5210', 'WhatsApp charges', 'expense', 'cost_of_sales', 'whatsapp_charges', "Meta's charges for WhatsApp messages."],
        ];
        foreach ($accounts as [$code, $name, $type, $category, $key, $description]) {
            if (DB::table('ledger_accounts')->where('system_key', $key)->exists()) {
                continue;
            }
            $taken = DB::table('ledger_accounts')->where('code', $code)->exists();
            DB::table('ledger_accounts')->insert([
                'code' => $taken ? $code.'W' : $code, 'name' => $name, 'type' => $type, 'category' => $category, 'system_key' => $key,
                'description' => $description, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('ledger_accounts')->whereIn('system_key', ['whatsapp_income', 'whatsapp_charges'])
            ->whereNotExists(fn ($q) => $q->from('journal_lines')->whereColumn('journal_lines.ledger_account_id', 'ledger_accounts.id'))
            ->delete();
        DB::table('app_settings')->whereIn('key', ['whatsapp.starter_credits', 'whatsapp.low_balance_threshold', 'whatsapp.templates'])->delete();
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropUnique(['wamid']);
            $table->dropColumn(['channel', 'wamid']);
        });
        Schema::dropIfExists('whatsapp_credit_transactions');
        Schema::dropIfExists('whatsapp_bundles');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_opted_in_at', 'whatsapp_opted_out_at', 'whatsapp_failed_at']);
        });
        Schema::table('landlords', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_balance', 'alert_channels']);
        });
    }
};
