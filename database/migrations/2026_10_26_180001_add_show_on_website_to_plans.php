<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('show_on_website')->default(false)->after('is_active');
        });

        // The marketing site has so far shown the unit-tiered pricing, so start
        // by publishing active tiered plans. Staff can change this per plan.
        DB::table('plans')->where('is_active', true)->where('pricing_model', 'tiered')->update(['show_on_website' => true]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('show_on_website');
        });
    }
};
