<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bank rules auto-categorize imported statement lines: when an unmatched line's
     * description matches the pattern, the rule's account is suggested as the
     * contra account for the "Add" action. Evaluated in priority order.
     */
    public function up(): void
    {
        Schema::create('bank_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('match_type', 20)->default('contains');
            $table->string('match_pattern');
            $table->foreignId('action_account_id')->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_rules');
    }
};
