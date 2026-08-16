<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the milestone payment schedule is printed on the invoice PDF and
     * shown on the customer's online invoice. On by default so a schedule, once
     * added, is visible to the customer.
     */
    public function up(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->boolean('show_payment_schedule')->default(true)->after('show_document_discount');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->dropColumn('show_payment_schedule');
        });
    }
};
