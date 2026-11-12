<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A failed SMS the landlord resent points at the new attempt, so it can't be resent twice. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->foreignId('resent_log_id')->nullable()->after('error')->constrained('sms_logs')->nullOnDelete();
            $table->timestamp('resent_at')->nullable()->after('resent_log_id');
        });
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resent_log_id');
            $table->dropColumn('resent_at');
        });
    }
};
