<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant sign-in codes can go by email as well as SMS: `msisdn` becomes
 * `destination` (a phone number or an email address) with a `channel`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_login_codes', function (Blueprint $table) {
            $table->renameColumn('msisdn', 'destination');
        });

        Schema::table('tenant_login_codes', function (Blueprint $table) {
            $table->string('destination', 255)->change();
            $table->string('channel', 10)->default('sms')->after('id');
        });
    }

    public function down(): void
    {
        DB::table('tenant_login_codes')->where('channel', 'email')->delete();

        Schema::table('tenant_login_codes', function (Blueprint $table) {
            $table->dropColumn('channel');
        });

        Schema::table('tenant_login_codes', function (Blueprint $table) {
            $table->string('destination', 20)->change();
            $table->renameColumn('destination', 'msisdn');
        });
    }
};
