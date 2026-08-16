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
        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('credit_memo_id')->nullable()->after('contact_id');
            $table->index(['company_id', 'credit_memo_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'credit_memo_id']);
            $table->dropColumn('credit_memo_id');
        });
    }
};
