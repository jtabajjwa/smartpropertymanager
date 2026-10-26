<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The number of units a landlord's paid subscription tier covers. Null means
 * no limit (free trial, flat-price plan, top open-ended tier, or not set yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landlords', function (Blueprint $table) {
            $table->unsignedInteger('max_units')->nullable()->after('billing_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('landlords', function (Blueprint $table) {
            $table->dropColumn('max_units');
        });
    }
};
