<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flags a vendor as a 1099 contractor (US only). Year-end disbursements to
     * flagged vendors are summed by the 1099 Summary report. The vendor's tax id
     * (EIN/SSN) reuses the existing contacts.tax_number column.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('track_1099')->default(false)->after('tax_number');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('track_1099');
        });
    }
};
