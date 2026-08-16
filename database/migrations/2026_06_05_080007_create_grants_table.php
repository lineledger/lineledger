<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A grant from a funder. Posting the award books DR deposit/receivable / CR the
     * deferred-restricted liability (ASNPO deferral) or CR grant revenue directly
     * (restricted-fund method or unrestricted). Deferred grants recognize revenue
     * over time via grant_recognitions.
     */
    public function up(): void
    {
        Schema::create('grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('funder_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('grant_no', 40);
            $table->string('name');
            $table->string('status', 20)->default('draft');
            $table->bigInteger('award_amount_cents')->default(0);
            $table->string('currency_code', 3)->nullable();
            $table->boolean('is_restricted')->default(true);
            $table->foreignId('fund_id')->nullable()->constrained('funds')->nullOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->boolean('receivable_on_award')->default(false);
            $table->foreignId('deposit_to_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('deferred_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('revenue_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('recognition_method', 20)->default('manual');
            $table->bigInteger('recognized_to_date_cents')->default(0);
            $table->foreignId('award_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'grant_no']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grants');
    }
};
