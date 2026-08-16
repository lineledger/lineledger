<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // QuickBooks "Warn if duplicate bill number is used." On by default.
            $table->boolean('warn_duplicate_bill_no')->default(true)->after('auto_apply_customer_credits');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('warn_duplicate_bill_no');
        });
    }
};
