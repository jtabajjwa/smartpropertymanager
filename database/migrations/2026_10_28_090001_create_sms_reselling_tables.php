<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SMS reselling: Primora buys SMS from providers (sms_purchases) and sells
 * credits to landlords in bundles (sms_bundles). Every change to a
 * landlord's balance is a row in sms_credit_transactions; landlords.sms_balance
 * is the running total. Sending an SMS uses credits per 160-character part.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('sms_bundles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedInteger('quantity');          // SMS credits
            $table->decimal('price', 12, 2);              // UGX
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('sms_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 100);              // e.g. Marz
            $table->unsignedInteger('quantity');
            $table->decimal('total_cost', 14, 2);         // UGX paid to the provider
            $table->date('purchased_on');
            $table->string('reference', 100)->nullable(); // provider invoice / receipt
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by_staff_user_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sms_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                   // purchase | starter | usage | refund | adjustment
            $table->integer('quantity');                  // + adds credit, − uses it
            $table->integer('balance_after');
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sms_log_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_user_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['landlord_id', 'id']);
            $table->index(['type', 'created_at']);
        });

        Schema::table('landlords', function (Blueprint $table) {
            $table->integer('sms_balance')->default(0)->after('max_units');
        });

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->unsignedSmallInteger('segments')->default(1)->after('message');        // 160-char parts
            $table->unsignedSmallInteger('credits_charged')->default(0)->after('segments');
        });

        // Defaults staff can change under Staff → SMS → Settings.
        $now = now();
        DB::table('app_settings')->insert([
            ['key' => 'sms.starter_credits', 'value' => '50', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'sms.low_balance_threshold', 'value' => '20', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Existing landlords have been getting SMS free; give them the starter
        // allowance so reminders don't stop the moment metering starts.
        foreach (DB::table('landlords')->pluck('id') as $landlordId) {
            DB::table('landlords')->where('id', $landlordId)->update(['sms_balance' => 50]);
            DB::table('sms_credit_transactions')->insert([
                'landlord_id' => $landlordId, 'type' => 'starter', 'quantity' => 50, 'balance_after' => 50,
                'note' => 'Starter allowance when SMS credits were introduced', 'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropColumn(['segments', 'credits_charged']);
        });
        Schema::table('landlords', function (Blueprint $table) {
            $table->dropColumn('sms_balance');
        });
        Schema::dropIfExists('sms_credit_transactions');
        Schema::dropIfExists('sms_purchases');
        Schema::dropIfExists('sms_bundles');
        Schema::dropIfExists('app_settings');
    }
};
