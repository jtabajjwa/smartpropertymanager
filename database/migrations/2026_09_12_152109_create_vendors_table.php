<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->enum('category', [
                'electrician',
                'plumber',
                'garbage_collector',
                'security',
                'landscaper',
                'cleaner',
                'painter',
                'carpenter',
                'other',
            ])->default('other');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['landlord_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
