<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('min_units');
            $table->unsignedInteger('max_units')->nullable();
            $table->decimal('monthly_price', 10, 2);
            $table->decimal('quarterly_price', 10, 2);
            $table->decimal('annual_price', 10, 2);
            $table->timestamps();

            $table->index(['plan_id', 'min_units']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_tiers');
    }
};
