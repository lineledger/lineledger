<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Hottest path: every financial report and the bank register/reconciliation
        // filter is_posted alongside company_id + entry_date. The existing
        // (company_id, entry_date) index ignores the highly selective is_posted predicate.
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->index(['company_id', 'is_posted', 'entry_date']);
        });

        // AR aging + contact statements filter status (whereIn) then range over invoice_date.
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['company_id', 'status', 'invoice_date']);
        });

        // AP aging filters status (whereIn) then range over due_date.
        Schema::table('bills', function (Blueprint $table) {
            $table->index(['company_id', 'status', 'due_date']);
        });

        // Contact statements (AR side) filter contact_id + status.
        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->index(['company_id', 'contact_id', 'status']);
        });

        // Contact statements (AP side) filter contact_id + status.
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->index(['company_id', 'contact_id', 'status']);
        });

        // Audit log page filters company_id + recorded_at range; only a standalone
        // company_id index (the FK) exists today.
        Schema::table('security_logs', function (Blueprint $table) {
            $table->index(['company_id', 'recorded_at']);
        });

        // Redundant: company_id is already UNIQUE (one row per company), so adding
        // status to the index buys nothing. The FK is satisfied by the unique index.
        Schema::table('data_migration_runs', function (Blueprint $table) {
            $table->dropIndex('data_migration_runs_company_id_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_posted', 'entry_date']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status', 'invoice_date']);
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status', 'due_date']);
        });

        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'contact_id', 'status']);
        });

        Schema::table('bill_payments', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'contact_id', 'status']);
        });

        Schema::table('security_logs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'recorded_at']);
        });

        Schema::table('data_migration_runs', function (Blueprint $table) {
            $table->index(['company_id', 'status']);
        });
    }
};
