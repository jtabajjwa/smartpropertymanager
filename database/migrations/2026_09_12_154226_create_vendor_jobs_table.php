<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('estate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 20)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('job_date');
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'cancelled'])->default('scheduled');
            $table->decimal('cost', 10, 2)->nullable();
            $table->timestamps();

            $table->index(['landlord_id', 'vendor_id']);
            $table->index(['landlord_id', 'status']);
            $table->unique(['landlord_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_jobs');
    }
};
