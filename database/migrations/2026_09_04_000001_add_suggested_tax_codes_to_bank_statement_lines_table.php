<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tax code(s) suggested for — or chosen on — an imported outflow. The
     * statement amount is treated as tax-inclusive when the line is recorded as
     * an expense. Suggest-only until the line is confirmed.
     */
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->foreignId('suggested_tax_code_id')->nullable()->after('suggested_bill_id')->constrained('tax_codes')->nullOnDelete();
            $table->foreignId('suggested_secondary_tax_code_id')->nullable()->after('suggested_tax_code_id')->constrained('tax_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suggested_secondary_tax_code_id');
            $table->dropConstrainedForeignId('suggested_tax_code_id');
        });
    }
};
