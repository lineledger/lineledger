<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The roles an invitation grants once accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_invitation_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_invitation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['company_invitation_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invitation_roles');
    }
};
