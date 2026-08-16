<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Funds are a transaction-tagging dimension (mirroring classifications/locations)
     * for the restricted fund method. Each fund carries a restriction type and one
     * fund per company is the default "General Fund" catch-all.
     */
    public function up(): void
    {
        Schema::create('funds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('fund_type')->default('restricted');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funds');
    }
};
