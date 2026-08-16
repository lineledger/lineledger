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
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('features_estimates')->default(true);
            $table->boolean('features_sales_orders')->default(true);
            $table->boolean('features_recurring_invoices')->default(true);
            $table->boolean('features_recurring_bills')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'features_estimates',
                'features_sales_orders',
                'features_recurring_invoices',
                'features_recurring_bills',
            ]);
        });
    }
};
