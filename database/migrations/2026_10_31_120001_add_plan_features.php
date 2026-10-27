<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Features a plan includes (null = every feature, including ones added later),
 * and features granted to one landlord whatever their plan (e.g. kept when
 * their plan stops including them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', fn (Blueprint $table) => $table->json('features')->nullable()->after('show_on_website'));
        Schema::table('landlords', fn (Blueprint $table) => $table->json('extra_features')->nullable());
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('features'));
        Schema::table('landlords', fn (Blueprint $table) => $table->dropColumn('extra_features'));
    }
};
