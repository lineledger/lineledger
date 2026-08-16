<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A user may mark a device "trusted" on the 2FA challenge so future
        // logins on that device skip the prompt. Each trusted device is one
        // row: the cookie holds a random token, we store only its SHA-256 hash
        // so a database leak never yields a usable token. User-auth infra (akin
        // to remember_token) — deliberately NOT tenant data, so it is not part
        // of the per-company backup registry.
        Schema::create('two_factor_remembered_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_remembered_devices');
    }
};
