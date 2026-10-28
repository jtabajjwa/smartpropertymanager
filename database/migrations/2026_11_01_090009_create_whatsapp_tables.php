<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant WhatsApp bot: one conversation per WhatsApp number (who it is, where
 * the conversation is), and a log of every message in and out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('wa_id', 20)->unique();          // the sender's number, e.g. 256772123456
            $table->string('profile_name')->nullable();
            // Signed in by a code (SMS/email) rather than by their own number: how, and where it went.
            $table->string('via', 10)->nullable();
            $table->string('via_to')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable(); // the account they're using
            $table->string('state', 40)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('wa_id', 20)->index();
            $table->string('direction', 3);                  // in | out
            $table->string('wamid')->nullable()->unique();   // WhatsApp's message id
            $table->string('type', 20);
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 20)->nullable();        // out: sent | delivered | read | failed | logged
            $table->text('error')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_sessions');
    }
};
