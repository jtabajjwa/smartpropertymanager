<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->nullableMorphs('payable');
            $table->decimal('amount', 10, 2);
            $table->date('payment_date');
            $table->enum('payment_method', ['cash', 'bank_transfer', 'mtn_mobile_money', 'airtel_mobile_money', 'flexipay']);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('receipt_photo')->nullable();
            $table->enum('status', ['unallocated', 'partially_allocated', 'fully_allocated', 'cancelled'])->default('unallocated');
            $table->timestamps();

            $table->index('payment_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
