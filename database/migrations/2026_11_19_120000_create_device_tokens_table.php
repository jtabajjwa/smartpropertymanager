<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phones signed in to the mobile app that can receive push notifications
 * (Firebase Cloud Messaging): whose they are (a landlord's user or a
 * tenant) and the app sign-in they belong to, so signing out stops them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->unsignedBigInteger('access_token_id')->nullable()->index();
            $table->string('token', 500)->unique();
            $table->string('platform', 20)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
