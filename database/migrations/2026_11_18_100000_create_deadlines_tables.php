<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deadlines: an organization's filing and payment calendar (GST/HST returns,
 * source-deduction remittances, instalments, licence renewals, …).
 *
 *  - `deadlines` is the series: what is due, who looks after it, and the rule
 *    that works out each period and its due date.
 *  - `deadline_occurrences` is one dated instance per period, materialized a
 *    rolling horizon ahead so each can be assigned, completed (by whom, when,
 *    for how much) or skipped on its own. `occurrence_key` (the period start,
 *    or the due date when there is no period) keeps regeneration idempotent.
 *    Occurrences due before `track_from` are never generated, so adding a
 *    deadline part-way through a year doesn't flood the list with overdue ones.
 *  - `deadline_notification_settings` holds each member's email choices.
 *  - `deadline_notification_logs` records every email sent; the unique
 *    `dedupe_key` is what stops the hourly run sending one twice.
 *
 * Nothing here touches the general ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deadlines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('category', 40);
            $table->string('kind', 20);
            $table->string('agency_name', 160)->nullable();
            $table->foreignId('tax_agency_id')->nullable()->constrained('tax_agencies')->nullOnDelete();
            $table->string('account_reference', 120)->nullable();
            $table->string('portal_url', 500)->nullable();
            $table->text('instructions')->nullable();
            $table->bigInteger('expected_amount_cents')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('notify_days_before')->nullable();

            $table->string('frequency', 20);
            $table->unsignedSmallInteger('interval')->default(1);
            $table->date('period_anchor')->nullable();
            $table->date('due_date')->nullable();
            $table->date('track_from')->nullable();
            $table->json('annual_dates')->nullable();
            $table->unsignedSmallInteger('due_months_after')->default(0);
            $table->string('due_day_rule', 20)->default('same_day');
            $table->unsignedTinyInteger('due_day_of_month')->nullable();
            $table->unsignedSmallInteger('due_days_after')->default(0);
            $table->string('weekend_adjustment', 30)->default('keep');
            $table->string('end_type', 30)->default('never');
            $table->date('end_date')->nullable();
            $table->unsignedInteger('max_occurrences')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->string('template_key', 80)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'archived_at']);
            $table->index(['company_id', 'template_key']);
        });

        Schema::create('deadline_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deadline_id')->constrained('deadlines')->cascadeOnDelete();
            $table->string('occurrence_key', 10);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('unadjusted_due_date');
            $table->date('due_date');
            $table->string('status', 20)->default('open');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('completed_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->bigInteger('amount_cents')->nullable();
            $table->string('confirmation_number', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('skipped_reason', 255)->nullable();
            $table->timestamp('customized_at')->nullable();
            $table->timestamps();

            $table->unique(['deadline_id', 'occurrence_key']);
            $table->index(['company_id', 'status', 'due_date']);
            $table->index(['company_id', 'assigned_user_id', 'status']);
        });

        Schema::create('deadline_notification_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('email_reminders')->default(true);
            $table->string('digest', 20)->default('weekly');
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });

        Schema::create('deadline_notification_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deadline_occurrence_id')->nullable()->constrained('deadline_occurrences')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('dedupe_key', 120);
            $table->date('sent_on');
            $table->timestamps();

            $table->unique(['company_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deadline_notification_logs');
        Schema::dropIfExists('deadline_notification_settings');
        Schema::dropIfExists('deadline_occurrences');
        Schema::dropIfExists('deadlines');
    }
};
