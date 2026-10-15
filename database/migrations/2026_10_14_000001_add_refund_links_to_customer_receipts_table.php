<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A refund can return a payment's unapplied credit (an overpayment) as well
     * as a credit memo's. refunded_receipt_id names the customer receipt whose
     * credit is returned; from_invoice_id names the invoice the refund was
     * issued from, so the invoice can list it.
     */
    public function up(): void
    {
        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('refunded_receipt_id')->nullable()->after('credit_memo_id');
            $table->unsignedBigInteger('from_invoice_id')->nullable()->after('refunded_receipt_id');
            $table->index(['company_id', 'refunded_receipt_id']);
            $table->index(['company_id', 'from_invoice_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'refunded_receipt_id']);
            $table->dropIndex(['company_id', 'from_invoice_id']);
            $table->dropColumn(['refunded_receipt_id', 'from_invoice_id']);
        });
    }
};
