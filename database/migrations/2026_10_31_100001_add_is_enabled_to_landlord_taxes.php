<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Switching a tax off keeps its recorded payments (rather than deleting the row). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landlord_taxes', function (Blueprint $table) {
            $table->boolean('is_enabled')->default(true)->after('estate_id');
        });
    }

    public function down(): void
    {
        Schema::table('landlord_taxes', fn (Blueprint $table) => $table->dropColumn('is_enabled'));
    }
};
