<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landlord_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained()->cascadeOnDelete();
            $table->string('sequence_key');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['landlord_id', 'sequence_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landlord_number_sequences');
    }
};
