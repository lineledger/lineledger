<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('deposit_no', 40);
            $table->date('deposit_date');
            $table->text('memo')->nullable();
            $table->bigInteger('amount_cents')->default(0);
            $table->string('status', 20)->default('draft'); // draft|posted|void
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'deposit_no']);
            $table->index(['company_id', 'deposit_date']);
            $table->index(['company_id', 'bank_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};
