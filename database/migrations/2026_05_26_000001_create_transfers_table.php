<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A first-class account transfer: money moving from one account to another
     * (DR to_account / CR from_account). Same-currency transfers set
     * from_amount_cents == to_amount_cents; a cross-currency transfer carries each
     * amount in its own account's currency and any home-value spread posts to the
     * company Exchange Gain/Loss account.
     *
     * NULL currency_code = the company home currency, mirroring the other documents.
     */
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('transfer_no', 40);
            $table->date('transfer_date');
            $table->text('memo')->nullable();
            $table->bigInteger('from_amount_cents')->default(0); // leaving source, in source currency
            $table->bigInteger('to_amount_cents')->default(0);   // arriving, in destination currency
            $table->char('from_currency_code', 3)->nullable();   // null = home
            $table->char('to_currency_code', 3)->nullable();     // null = home
            $table->decimal('from_fx_rate', 18, 8)->nullable();
            $table->decimal('to_fx_rate', 18, 8)->nullable();
            $table->bigInteger('home_amount_cents')->nullable();
            $table->string('status', 20)->default('draft'); // draft|posted|void
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'transfer_no']);
            $table->index(['company_id', 'transfer_date']);
            $table->index(['company_id', 'from_account_id']);
            $table->index(['company_id', 'to_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
