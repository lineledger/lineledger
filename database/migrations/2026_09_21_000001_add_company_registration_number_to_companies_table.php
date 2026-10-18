<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Companies House / corporate registry number (distinct from VAT/GST tax_number).
     * Used for UK Ltd/LLP company numbers; other jurisdictions may adopt it later.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('company_registration_number', 32)->nullable()->after('tax_number');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('company_registration_number');
        });
    }
};
