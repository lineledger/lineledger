<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A recorded source-deduction remittance for one agency + period. Snapshots
        // the amounts remitted (the calculator summary at record time) and links the
        // balanced journal entry that DRs the statutory payables / CRs the bank —
        // mirroring tax_return_payments for sales tax.
        Schema::create('payroll_remittances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('agency', 20);    // cra | revenu_quebec
            $table->string('frequency', 20);  // snapshot of the frequency used
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            $table->string('status', 12)->default('paid'); // paid | void
            $table->bigInteger('total_cents')->default(0);
            $table->json('breakdown')->nullable(); // component snapshot from the calculator
            $table->foreignId('bank_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->date('payment_date');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Lookup by agency + period. Uniqueness of a *paid* remittance per
            // period is enforced in the action (so a voided one can be re-recorded).
            $table->index(['company_id', 'agency', 'period_start'], 'payroll_remit_agency_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_remittances');
    }
};
