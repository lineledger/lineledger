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
            // Which header fields are shown on the invoice entry form.
            $table->boolean('show_terms')->default(true)->after('show_tax_column');
            $table->boolean('show_sales_rep')->default(true)->after('show_terms');
            $table->boolean('show_customer_po')->default(true)->after('show_sales_rep');
            $table->boolean('show_ship_date')->default(true)->after('show_customer_po');
            $table->boolean('show_ship_via')->default(true)->after('show_ship_date');
            $table->boolean('show_fob')->default(true)->after('show_ship_via');
            $table->boolean('show_tracking_no')->default(true)->after('show_fob');
            $table->boolean('show_memo')->default(true)->after('show_tracking_no');
            $table->boolean('show_customer_message')->default(true)->after('show_memo');

            // Which optional line columns are shown on the invoice entry form.
            $table->boolean('show_service_date_column')->default(true)->after('show_customer_message');
            $table->boolean('show_account_column')->default(true)->after('show_service_date_column');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->dropColumn([
                'show_terms',
                'show_sales_rep',
                'show_customer_po',
                'show_ship_date',
                'show_ship_via',
                'show_fob',
                'show_tracking_no',
                'show_memo',
                'show_customer_message',
                'show_service_date_column',
                'show_account_column',
            ]);
        });
    }
};
