<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // The account number the supplier uses to identify us. Prints in the
            // memo of payment cheques, matching QuickBooks' supplier "Account no."
            $table->string('account_no', 100)->nullable()->after('company_name');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('account_no');
        });
    }
};
