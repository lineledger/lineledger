<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links an official receipt back to the Donation that booked the money. A
     * receipt spawned from a donation carries no debit_account_id, so the issuer
     * posts no GL for it — the donation already recorded the revenue (no double count).
     * The legacy customer_receipt_id path is left untouched.
     */
    public function up(): void
    {
        Schema::table('donation_receipts', function (Blueprint $table) {
            $table->foreignId('donation_id')->nullable()->after('customer_receipt_id')->constrained('donations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('donation_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('donation_id');
        });
    }
};
