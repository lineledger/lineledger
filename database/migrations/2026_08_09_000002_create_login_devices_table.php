<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tracks the (normalized) devices a user has logged in from, so a login
        // from an unseen device can trigger a "was this you?" email. User-scoped,
        // not company-scoped — deliberately NOT in the backup registry (same as
        // sessions and two_factor_remembered_devices).
        Schema::create('login_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_hash', 64);
            $table->string('user_agent', 512)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->unique(['user_id', 'device_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_devices');
    }
};
