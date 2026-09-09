<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_deposit_ledgers', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('lease_id')->constrained();
            $table->enum('type', ['deposit', 'refund', 'deduction']);
            $table->decimal('amount', 10, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('lease_id');
            $table->index('type');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_deposit_ledgers');
    }
};
