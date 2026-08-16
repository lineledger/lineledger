<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A document line can carry two sales taxes at once (e.g. GST + PST/QST in a
 * PST/QST province), each tracked and shown separately rather than merged into a
 * single combined rate. This adds the "second tax" slot alongside the existing
 * (primary) tax_code_id on every taxable document line.
 *
 * Tables are listed explicitly (not looped) so static analysis can resolve the
 * new columns. Lines that carry a per-line subtotal compute secondary_tax_cents
 * from it; cheque/expense lines (which tax amount_cents directly) use the same
 * column. Tables that already allow a manual tax override get a matching
 * secondary_tax_override_cents. recurring_document_lines store only the code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('secondary_tax_code_id')->nullable()->after('tax_code_id')->constrained('tax_codes')->nullOnDelete();
            $table->bigInteger('secondary_tax_cents')->default(0)->after('secondary_tax_code_id');
        });
        Schema::table('estimate_lines', function (Blueprint $table) {
            $table->foreignId('secondary_tax_code_id')->nullable()->after('tax_code_id')->constrained('tax_codes')->nullOnDelete();
            $table->bigInteger('secondary_tax_cents')->default(0)->after('secondary_tax_code_id');
        });
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->foreignId('secondary_tax_code_id')->nullable()->after('tax_code_id')->constrained('tax_codes')->nullOnDelete();
            $table->bigInteger('secondary_tax_cents')->default(0)->after('secondary_tax_code_id');
        });
        Schema::table('credit_memo_lines', function (Blueprint $table) {
            $table->foreignId('secondary_tax_code_id')->nullable()->after('tax_code_id')->constrained('tax_codes')->nullOnDelete();
            $table->bigInteger('secondary_tax_cents')->default(0)->after('secondary_tax_code_id');
        });
        Schema::table('sales_receipt_lines', function (Blueprint $table) {
            $table->foreignId('secondary_tax_code_id')->nullable()->after('tax_code_id')->constrained('tax_codes')->nullOnDelete();
            $table->bigInteger('secondary_tax_cents')->default(0)->after('secondary_tax_code_id');
        });
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->foreignId('secondary_tax_code_id')->nullable()->after('tax_code_id')->constrained('tax_codes')->nullOnDelete();
            $table->bigInteger('secondary_tax_cents')->default(0)->after('secondary_tax_code_id');
        });
        Schema::table('vendor_credit_lines', function (Blueprint $table) {
            $table->foreignId('secondary_tax_code_id')->nullable()->after('tax_code_id')->constrained('tax_codes')->nullOnDelete();
            $table->bigInteger('secondary_tax_cents')->default(0)->after('secondary_tax_code_id');
        });
        Schema::table('bill_lines', function (Blueprint $table) {
            $table->foreignId('secondary_tax_code_id')->nullable()->after('tax_code_id')->constrained('tax_codes')->nullOnDelete();
            $table->bigInteger('secondary_tax_cents')->default(0)->after('secondary_tax_code_id');
            $table->bigInteger('secondary_tax_override_cents')->nullable()->after('secondary_tax_cents');
        });
        Schema::table('cheque_lines', function (Blueprint $table) {
            $table->foreignId('secondary_tax_code_id')->nullable()->after('tax_code_id')->constrained('tax_codes')->nullOnDelete();
            $table->bigInteger('secondary_tax_cents')->default(0)->after('secondary_tax_code_id');
            $table->bigInteger('secondary_tax_override_cents')->nullable()->after('secondary_tax_cents');
        });
        Schema::table('expense_lines', function (Blueprint $table) {
            $table->foreignId('secondary_tax_code_id')->nullable()->after('tax_code_id')->constrained('tax_codes')->nullOnDelete();
            $table->bigInteger('secondary_tax_cents')->default(0)->after('secondary_tax_code_id');
            $table->bigInteger('secondary_tax_override_cents')->nullable()->after('secondary_tax_cents');
        });
        Schema::table('recurring_document_lines', function (Blueprint $table) {
            $table->foreignId('secondary_tax_code_id')->nullable()->after('tax_code_id')->constrained('tax_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('secondary_tax_code_id');
            $table->dropColumn('secondary_tax_cents');
        });
        Schema::table('estimate_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('secondary_tax_code_id');
            $table->dropColumn('secondary_tax_cents');
        });
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('secondary_tax_code_id');
            $table->dropColumn('secondary_tax_cents');
        });
        Schema::table('credit_memo_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('secondary_tax_code_id');
            $table->dropColumn('secondary_tax_cents');
        });
        Schema::table('sales_receipt_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('secondary_tax_code_id');
            $table->dropColumn('secondary_tax_cents');
        });
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('secondary_tax_code_id');
            $table->dropColumn('secondary_tax_cents');
        });
        Schema::table('vendor_credit_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('secondary_tax_code_id');
            $table->dropColumn('secondary_tax_cents');
        });
        Schema::table('bill_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('secondary_tax_code_id');
            $table->dropColumn(['secondary_tax_cents', 'secondary_tax_override_cents']);
        });
        Schema::table('cheque_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('secondary_tax_code_id');
            $table->dropColumn(['secondary_tax_cents', 'secondary_tax_override_cents']);
        });
        Schema::table('expense_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('secondary_tax_code_id');
            $table->dropColumn(['secondary_tax_cents', 'secondary_tax_override_cents']);
        });
        Schema::table('recurring_document_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('secondary_tax_code_id');
        });
    }
};
