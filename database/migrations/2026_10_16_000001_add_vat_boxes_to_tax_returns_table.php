<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The HMRC nine-box figures frozen when a UK VAT return is filed, so the
     * filed boxes survive later edits to the documents behind Boxes 6 and 7.
     * Null for non-UK returns and for drafts.
     */
    public function up(): void
    {
        Schema::table('tax_returns', function (Blueprint $table) {
            $table->json('vat_boxes')->nullable()->after('reconciliation');
        });
    }

    public function down(): void
    {
        Schema::table('tax_returns', function (Blueprint $table) {
            $table->dropColumn('vat_boxes');
        });
    }
};
