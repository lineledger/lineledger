<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Automated payment-reminder (dunning) support: a per-company tier schedule,
     * a once-per-(invoice, tier) send log for idempotency, plus per-invoice and
     * per-customer opt-outs.
     */
    public function up(): void
    {
        Schema::create('reminder_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Days relative to the due date the reminder fires: negative = before
            // due (a heads-up), positive = overdue.
            $table->integer('offset_days');
            $table->string('subject');
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('tier_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });

        Schema::create('invoice_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reminder_tier_id')->constrained()->cascadeOnDelete();
            $table->string('sent_to');
            $table->timestamp('sent_at');
            $table->timestamps();

            // One reminder per invoice per tier, ever — the idempotency guard.
            $table->unique(['invoice_id', 'reminder_tier_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('reminders_enabled')->default(true)->after('status');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('reminders_muted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn('reminders_muted'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('reminders_enabled'));
        Schema::dropIfExists('invoice_reminder_logs');
        Schema::dropIfExists('reminder_tiers');
    }
};
