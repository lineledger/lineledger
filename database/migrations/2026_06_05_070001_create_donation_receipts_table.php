<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Official CRA donation receipts. The donor name + address are snapshotted so
     * an issued receipt never mutates if the contact is later edited. Eligible
     * amount = fair market value − advantage. Cash receipts are record-only
     * (journal_entry_id null, customer_receipt_id links the booking); in-kind
     * receipts post their own GL entry at FMV.
     */
    public function up(): void
    {
        Schema::create('donation_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();

            $table->string('receipt_no');
            $table->string('status')->default('draft');
            $table->string('gift_type')->default('cash');

            $table->date('gift_date');
            $table->date('issued_date')->nullable();

            // Donor snapshot (CRA-required on the receipt).
            $table->string('donor_name');
            $table->string('donor_line1')->nullable();
            $table->string('donor_line2')->nullable();
            $table->string('donor_city')->nullable();
            $table->string('donor_region')->nullable();
            $table->string('donor_postal_code')->nullable();
            $table->string('donor_country')->nullable();

            $table->bigInteger('amount_cents');                 // fair market value
            $table->bigInteger('advantage_cents')->default(0);  // value of advantage to the donor
            $table->bigInteger('eligible_amount_cents');        // = amount − advantage (frozen at issue)
            $table->text('advantage_description')->nullable();
            $table->text('in_kind_description')->nullable();
            $table->string('appraised_by')->nullable();
            $table->date('appraisal_date')->nullable();
            $table->string('currency_code')->nullable();

            $table->foreignId('revenue_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('debit_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('customer_receipt_id')->nullable()->constrained('customer_receipts')->nullOnDelete();
            $table->foreignId('reissued_from_id')->nullable()->constrained('donation_receipts')->nullOnDelete();

            $table->boolean('is_consolidated')->default(false);
            $table->smallInteger('consolidation_year')->nullable();
            $table->timestamp('email_sent_at')->nullable();

            $table->string('void_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'receipt_no']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_receipts');
    }
};
