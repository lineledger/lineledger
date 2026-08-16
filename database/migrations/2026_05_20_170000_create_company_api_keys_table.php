<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label', 80);
            $table->string('prefix', 16);
            $table->char('token_hash', 64)->unique();
            $table->char('last_four', 4);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'revoked_at']);
            $table->index('prefix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_api_keys');
    }
};
