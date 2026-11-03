<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who resolved a payment by hand (marked it succeeded or failed), when and why. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->foreignId('resolved_by_staff_user_id')->nullable()->after('initiated_by_staff_user_id')->constrained('staff_users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('applied_at');
            $table->string('resolution_note', 500)->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by_staff_user_id');
            $table->dropColumn(['resolved_at', 'resolution_note']);
        });
    }
};
