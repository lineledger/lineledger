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
        Schema::table('invoice_settings', function (Blueprint $table) {
            // Default income account used to code invoice/line items when no account
            // is set (e.g. a manual line, or the hidden Account column).
            $table->foreignId('default_sales_account_id')->nullable()->after('company_id')->constrained('accounts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_sales_account_id');
        });
    }
};
