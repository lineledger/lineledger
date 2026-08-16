<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A deposit line can now batch a pay-now Sales Receipt parked in Undeposited
     * Funds, the same way it already batches a CustomerReceipt. Exactly one of
     * customer_receipt_id / sales_receipt_id is set on a receipt-source line.
     */
    public function up(): void
    {
        Schema::table('deposit_lines', function (Blueprint $table) {
            $table->foreignId('sales_receipt_id')->nullable()->after('customer_receipt_id')
                ->constrained('sales_receipts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deposit_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_receipt_id');
        });
    }
};
