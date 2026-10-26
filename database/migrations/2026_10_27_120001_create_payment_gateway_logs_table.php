<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every exchange with a payment gateway: our requests to it (with secrets
 * masked) and its replies, plus callbacks it posts to us. For support and
 * reconciling the pool account. Callbacks that match no payment are kept
 * too (payment_transaction_id null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('gateway', 30);
            $table->string('action', 50);                 // acdepositfunds, actransactioncheckstatus, ipn, failure, …
            $table->string('direction', 10);              // outbound (we called them) | inbound (they called us)
            $table->string('url', 500)->nullable();
            $table->longText('request_body')->nullable(); // credentials masked
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('note', 500)->nullable();      // e.g. "Signature verified" / "No matching payment"
            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_transaction_id', 'id']);
            $table->index(['gateway', 'direction', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_logs');
    }
};
