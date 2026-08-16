<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portion of a bill settled OUTSIDE the bill-payment system — chiefly a general
     * journal entry that already posted to the AP control account (common after a
     * full-history migration, where write-offs and rounding were booked as JEs
     * rather than bill payments). It closes the document balance without any new GL
     * posting, kept separate from amount_paid_cents so the bill-payment poster's
     * canonical "sum of applications" recompute never clobbers it. Mirror of
     * invoices.reconciled_cents.
     */
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->integer('reconciled_cents')->default(0)->after('amount_paid_cents');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('reconciled_cents');
        });
    }
};
