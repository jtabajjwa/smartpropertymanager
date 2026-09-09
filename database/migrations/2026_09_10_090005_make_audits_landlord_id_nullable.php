<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->dropForeign(['landlord_id']);
        });

        Schema::table('audits', function (Blueprint $table) {
            $table->unsignedBigInteger('landlord_id')->nullable()->change();
        });

        Schema::table('audits', function (Blueprint $table) {
            $table->foreign('landlord_id')->references('id')->on('landlords')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->dropForeign(['landlord_id']);
        });

        Schema::table('audits', function (Blueprint $table) {
            $table->unsignedBigInteger('landlord_id')->nullable(false)->change();
        });

        Schema::table('audits', function (Blueprint $table) {
            $table->foreign('landlord_id')->references('id')->on('landlords')->restrictOnDelete();
        });
    }
};
