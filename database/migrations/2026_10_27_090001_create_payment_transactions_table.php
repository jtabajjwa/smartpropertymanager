<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money collected through a payment gateway (mobile money via Yo! Payments
 * for now). One row per attempt, whatever it is for: a landlord's
 * subscription now, tenants' rent later ("purpose").
 *
 * Not landlord-scoped by a global scope: gateway callbacks and the scheduled
 * reconciler work across landlords, so landlord-side reads filter explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();            // ours; sent as ExternalReference
            $table->string('purpose', 30);                        // subscription | rent | …
            $table->foreignId('landlord_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('payable');                    // what is being paid for
            $table->string('gateway', 30);                        // yo | fake | …
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('UGX');
            $table->string('msisdn', 20);                         // payer's number, 2567XXXXXXXX
            $table->string('narrative', 500);
            $table->string('status', 20)->default('pending');     // pending | succeeded | failed | indeterminate
            $table->string('gateway_reference', 100)->nullable(); // Yo! TransactionReference
            $table->string('network_reference', 150)->nullable(); // MNO reference
            $table->string('receipt_number', 100)->nullable();
            $table->string('status_message', 500)->nullable();
            $table->json('context')->nullable();                  // purpose details (plan, cycle, units…)
            $table->json('last_response')->nullable();            // last raw gateway answer, for support
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('initiated_by_staff_user_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('notified_at')->nullable();         // last IPN / failure notification
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('applied_at')->nullable();          // purpose effect applied (exactly once)
            $table->timestamps();

            $table->index(['status', 'last_checked_at']);
            $table->index(['landlord_id', 'purpose']);
            $table->index('gateway_reference');
        });

        // The plan and billing cycle a landlord last paid for.
        Schema::table('landlords', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('subscription_status')->constrained()->nullOnDelete();
            $table->string('billing_cycle', 20)->nullable()->after('plan_id'); // monthly | quarterly | annual
        });
    }

    public function down(): void
    {
        Schema::table('landlords', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn('billing_cycle');
        });

        Schema::dropIfExists('payment_transactions');
    }
};
