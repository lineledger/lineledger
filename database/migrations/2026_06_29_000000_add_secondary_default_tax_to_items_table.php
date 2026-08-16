<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An item can carry two default sales taxes (e.g. GST + PST/QST), mirroring the
 * primary/secondary tax slots already on document lines. This adds the second
 * default-tax slot alongside the existing default_tax_code_id; both prefill onto
 * a line when the item is selected on an invoice, bill, etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('default_secondary_tax_code_id')->nullable()->after('default_tax_code_id')->constrained('tax_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_secondary_tax_code_id');
        });
    }
};
