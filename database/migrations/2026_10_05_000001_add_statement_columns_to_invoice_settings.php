<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The company's default optional columns on the customer statement, per
     * statement type: {"open-invoices": [...], "activity": [...]}. Null (or a
     * missing type) means the built-in defaults in StatementColumns.
     */
    public function up(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->json('statement_columns')->nullable()->after('payment_instructions');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->dropColumn('statement_columns');
        });
    }
};
