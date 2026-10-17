<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landlords', function (Blueprint $table) {
            $table->string('logo')->nullable()->after('address');
            $table->string('brand_color', 7)->default('#c1704a')->after('logo');
            $table->text('invoice_footer_note')->nullable()->after('brand_color');
        });
    }

    public function down(): void
    {
        Schema::table('landlords', function (Blueprint $table) {
            $table->dropColumn(['logo', 'brand_color', 'invoice_footer_note']);
        });
    }
};
