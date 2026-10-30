<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment reference handed to Yo!'s Custom USSD app for one tenancy. Yo!
 * sends the mobile money prompt itself; when its payment notification comes
 * back with this reference, a payment transaction is created from it (see
 * App\Ussd\UssdPayments). References nobody paid against simply expire.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ussd_payment_intents')) {
            return;
        }

        Schema::create('ussd_payment_intents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('landlord_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->nullable()->constrained()->nullOnDelete();
            $table->string('msisdn', 20);                 // the phone the USSD session was dialled from
            $table->decimal('amount', 14, 2)->nullable(); // when the amount was known at the call-out
            $table->string('status', 20)->default('open'); // open | claimed | expired
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ussd_payment_intents');
    }
};
