<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->longText('content');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['landlord_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_templates');
    }
};
