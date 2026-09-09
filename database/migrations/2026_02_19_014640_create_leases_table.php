<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('unit_id')->constrained();
            $table->enum('status', ['active', 'terminated', 'evicted'])->default('active');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('rent_amount', 10, 2);
            $table->enum('billing_cycle', ['daily', 'weekly', 'fortnightly', 'monthly', 'quarterly', 'yearly']);
            $table->decimal('security_deposit', 10, 2)->default(0);
            $table->integer('required_upfront_periods')->default(1);
            $table->string('agreement_file')->nullable();
            $table->date('next_invoice_date');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('unit_id');
            $table->index('status');
            $table->index('next_invoice_date');
            $table->index('end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leases');
    }
};
