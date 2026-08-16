<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->foreignId('converted_sales_order_id')->nullable()->after('converted_invoice_id')
                ->constrained('sales_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_sales_order_id');
        });
    }
};
