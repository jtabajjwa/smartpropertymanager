<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_password_reset_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 6);
            $table->timestamp('expires_at');
            $table->boolean('used')->default(false);
            $table->timestamps();

            $table->index(['staff_user_id', 'code', 'used']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_password_reset_codes');
    }
};
