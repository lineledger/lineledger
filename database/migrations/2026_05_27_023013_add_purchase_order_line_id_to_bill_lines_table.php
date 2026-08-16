<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links a bill line to the PO line it draws down, so a PO's billed/backordered
     * quantities derive live from non-void bills. Mirror of
     * invoice_lines.sales_order_line_id.
     */
    public function up(): void
    {
        Schema::table('bill_lines', function (Blueprint $table) {
            $table->foreignId('purchase_order_line_id')->nullable()->after('item_id')
                ->constrained('purchase_order_lines')->nullOnDelete();
            $table->index('purchase_order_line_id');
        });
    }

    public function down(): void
    {
        Schema::table('bill_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_line_id');
        });
    }
};
