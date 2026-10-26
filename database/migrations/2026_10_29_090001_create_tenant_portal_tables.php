<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant portal: SMS sign-in codes, issues (repairs / requests / complaints)
 * with a status log, and tenants paying rent by mobile money into the
 * Primora pool (collection fee tiers, settlements to landlords).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landlords', function (Blueprint $table) {
            // Switched on by Primora staff: tenants may pay this landlord by mobile money.
            $table->boolean('collect_payments')->default(false)->after('max_units');
            // The landlord's choice: add the collection fee to what tenants pay (otherwise the landlord bears it).
            $table->boolean('pass_collection_fee')->default(false)->after('collect_payments');
        });

        Schema::create('tenant_login_codes', function (Blueprint $table) {
            $table->id();
            $table->string('msisdn', 20)->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20)->nullable();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 20);                 // repair | request | complaint
            $table->string('title', 150);
            $table->text('description');
            $table->string('photo')->nullable();
            $table->string('status', 20)->default('open'); // open | in_progress | resolved | closed
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['landlord_id', 'status']);
            $table->index('tenant_id');
        });

        Schema::create('issue_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->string('author', 20);                   // tenant | landlord | staff
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_user_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->string('status', 20)->nullable();       // set when the status changed
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // Yo! charges for inbound payments by amount band; staff mirror those bands here.
        Schema::create('collection_fee_tiers', function (Blueprint $table) {
            $table->id();
            $table->decimal('min_amount', 14, 2);
            $table->decimal('max_amount', 14, 2)->nullable(); // null = and above
            $table->decimal('fixed_fee', 14, 2)->default(0);
            $table->decimal('percent_fee', 6, 3)->default(0);
            $table->timestamps();
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->decimal('fee_amount', 14, 2)->default(0)->after('amount');
            $table->string('fee_bearer', 10)->nullable()->after('fee_amount');       // landlord | tenant
            $table->decimal('landlord_amount', 14, 2)->nullable()->after('fee_bearer'); // owed to the landlord when it succeeds
        });

        // Money Primora has paid out to landlords from rent it collected for them.
        Schema::create('landlord_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('method', 30);
            $table->string('reference', 100)->nullable();
            $table->date('settled_on');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_staff_user_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['landlord_id', 'settled_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landlord_settlements');
        Schema::table('payment_transactions', fn (Blueprint $table) => $table->dropColumn(['fee_amount', 'fee_bearer', 'landlord_amount']));
        Schema::dropIfExists('collection_fee_tiers');
        Schema::dropIfExists('issue_updates');
        Schema::dropIfExists('issues');
        Schema::dropIfExists('tenant_login_codes');
        Schema::table('landlords', fn (Blueprint $table) => $table->dropColumn(['collect_payments', 'pass_collection_fee']));
    }
};
