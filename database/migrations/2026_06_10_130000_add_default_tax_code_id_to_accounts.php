<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Optional default tax code for Income / Expense accounts (QBO
            // parity) — later used to pre-fill transaction lines.
            $table->foreignId('default_tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['default_tax_code_id']);
            $table->dropColumn('default_tax_code_id');
        });
    }
};
