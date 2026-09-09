<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill any null emails from the landlord's contact email, so the
        // NOT NULL constraint below never fails on pre-existing rows.
        // Done row-by-row (not a cross-table UPDATE) since SQLite — used by
        // the test suite — doesn't support MySQL's UPDATE...JOIN syntax.
        DB::table('users')
            ->whereNull('email')
            ->select('users.id', 'users.landlord_id')
            ->orderBy('users.id')
            ->each(function ($user) {
                $contactEmail = DB::table('landlords')->where('id', $user->landlord_id)->value('contact_email');

                DB::table('users')->where('id', $user->id)->update(['email' => $contactEmail]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->after('id');
        });

        DB::table('users')->whereNull('username')->orderBy('id')->each(function ($user) {
            DB::table('users')->where('id', $user->id)->update(['username' => 'user_' . $user->id]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable(false)->unique()->change();
        });
    }
};
