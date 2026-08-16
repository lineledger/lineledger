<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portion of an invoice settled OUTSIDE the receipt system — chiefly a general
     * journal entry that already posted to the AR control account (common after a
     * full-history migration, where write-offs and rounding were booked as JEs
     * rather than receipts). It closes the document balance without any new GL
     * posting, kept separate from amount_paid_cents so the receipt poster's
     * canonical "sum of applications" recompute never clobbers it.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->integer('reconciled_cents')->default(0)->after('amount_paid_cents');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('reconciled_cents');
        });
    }
};
