<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optionally leave zero-quantity (and zero-amount) line items off the printed
     * invoice PDF. Defaults off, so existing invoices keep printing every line
     * until an owner enables it from invoice settings.
     */
    public function up(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->boolean('hide_zero_qty_lines')->default(false)->after('show_service_date_column');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->dropColumn('hide_zero_qty_lines');
        });
    }
};
