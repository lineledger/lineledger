<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();
            $table->date('statement_date');
            $table->bigInteger('beginning_balance_cents');
            $table->bigInteger('ending_balance_cents');

            $table->bigInteger('service_charge_cents')->default(0);
            $table->date('service_charge_date')->nullable();
            $table->foreignId('service_charge_account_id')->nullable()->constrained('accounts');
            $table->foreignId('service_charge_entry_id')->nullable()->constrained('journal_entries');

            $table->bigInteger('interest_earned_cents')->default(0);
            $table->date('interest_earned_date')->nullable();
            $table->foreignId('interest_earned_account_id')->nullable()->constrained('accounts');
            $table->foreignId('interest_earned_entry_id')->nullable()->constrained('journal_entries');

            $table->string('status')->default('in_progress');
            $table->json('marked_line_ids')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users');

            $table->timestamps();

            $table->index(['company_id', 'account_id', 'status']);
            $table->index(['account_id', 'statement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};
