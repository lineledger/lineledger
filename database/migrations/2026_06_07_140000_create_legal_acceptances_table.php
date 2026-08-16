<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document_key', 50);
            $table->string('version', 50);
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            // One immutable row per user + document + version: accepting the same
            // version twice is a no-op (firstOrCreate), while each new version
            // adds a fresh row so the agreement history is preserved.
            $table->unique(['user_id', 'document_key', 'version'], 'legal_acceptances_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
    }
};
