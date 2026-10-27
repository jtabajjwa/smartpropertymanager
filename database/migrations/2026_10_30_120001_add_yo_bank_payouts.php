<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank payouts can go through Yo! Payments (acwithdrawfundstobank) as well as
 * by direct EFT from Primora's bank; the executor chooses. Yo! only pays
 * bank accounts registered on Primora's Yo! account, identified by its
 * "API Bank Identifier".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_accounts', function (Blueprint $table) {
            $table->string('yo_bank_identifier', 100)->nullable()->after('swift_code');
            $table->json('yo_bank_info')->nullable()->after('yo_bank_identifier'); // bank legal name / address used to register it
            $table->timestamp('yo_registered_at')->nullable()->after('yo_bank_info');
        });

        Schema::table('liquidations', function (Blueprint $table) {
            $table->string('route', 10)->nullable()->after('method');          // yo | direct (how it was paid)
            $table->string('transfer_type', 10)->nullable()->after('route');   // Yo! bank: EFT | RTGS | null (automatic)
        });
    }

    public function down(): void
    {
        Schema::table('liquidations', fn (Blueprint $table) => $table->dropColumn(['route', 'transfer_type']));
        Schema::table('payout_accounts', fn (Blueprint $table) => $table->dropColumn(['yo_bank_identifier', 'yo_bank_info', 'yo_registered_at']));
    }
};
