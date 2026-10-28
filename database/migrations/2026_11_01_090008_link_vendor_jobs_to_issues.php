<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A job card can be booked for a tenant's issue (an issue can need several
 * jobs). share_vendor: whether the tenant is told which vendor is coming.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_jobs', function (Blueprint $table) {
            $table->foreignId('issue_id')->nullable()->after('unit_id')->constrained()->nullOnDelete();
            $table->boolean('share_vendor')->default(false)->after('issue_id');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_jobs', function (Blueprint $table) {
            $table->dropForeign(['issue_id']);
            $table->dropColumn(['issue_id', 'share_vendor']);
        });
    }
};
