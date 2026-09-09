<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->enum('type', ['individual', 'company']);
            $table->string('name');
            $table->string('email')->unique();
            $table->string('primary_phone');
            $table->string('secondary_phone')->nullable();
            $table->string('photo')->nullable();
            $table->string('lc_letter')->nullable();
            $table->enum('id_type', ['passport', 'national_id', 'drivers_license', 'business_registration', 'other'])->default('other');
            $table->string('id_number');
            $table->string('id_scan')->nullable();
            $table->string('emergency_contact_name');
            $table->enum('emergency_contact_relationship', ['family', 'friend', 'colleague', 'other'])->default('other');
            $table->string('emergency_contact_phone');
            $table->string('emergency_contact_email')->nullable();
            $table->string('emergency_contact_address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
