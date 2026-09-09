<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->nullableMorphs('invoiceable');
            $table->foreignId('lease_id')->nullable()->constrained();
            $table->enum('type', ['rent', 'security_deposit', 'utility']);
            $table->foreignId('utility_id')->nullable()->constrained();
            $table->string('title', 100);
            $table->date('invoice_date');
            $table->decimal('amount', 10, 2);
            $table->date('due_date');
            $table->enum('status', ['unpaid', 'partial', 'paid', 'cancelled', 'written_off'])->default('unpaid');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('lease_id');
            $table->index('status');
            $table->index('type');
            $table->index('due_date');
            $table->index('invoice_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
