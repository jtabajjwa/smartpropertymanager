<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The in-app tips and the dashboard's "Getting started" guide a user has
 * closed (keys, see App\Http\Controllers\GuideController).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'dismissed_tips')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('dismissed_tips')->nullable()->after('must_change_password');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'dismissed_tips')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('dismissed_tips');
            });
        }
    }
};
