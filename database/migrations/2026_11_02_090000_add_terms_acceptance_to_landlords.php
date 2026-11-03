<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which version of the Terms of Service a landlord accepted, when, and by whom (config/legal.php). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landlords', function (Blueprint $table) {
            $table->string('terms_version', 20)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->foreignId('terms_accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('terms_accepted_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('landlords', function (Blueprint $table) {
            $table->dropConstrainedForeignId('terms_accepted_by');
            $table->dropColumn(['terms_version', 'terms_accepted_at', 'terms_accepted_ip']);
        });
    }
};
