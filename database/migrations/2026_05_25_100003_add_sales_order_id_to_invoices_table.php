<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('sales_order_id')->nullable()->after('contact_id')
                ->constrained('sales_orders')->nullOnDelete();
            $table->index(['company_id', 'sales_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'sales_order_id']);
            $table->dropConstrainedForeignId('sales_order_id');
        });
    }
};
