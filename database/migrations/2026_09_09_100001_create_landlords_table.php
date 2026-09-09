<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landlords', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('trading_name')->nullable();
            $table->string('contact_email')->unique();
            $table->string('contact_phone')->unique();
            $table->string('address')->nullable();
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->string('subscription_status')->default('trialing');
            $table->date('current_period_end')->nullable();
            $table->date('grace_ends_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landlords');
    }
};
