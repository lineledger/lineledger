<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-document currency. NULL = the company home currency (read-time default),
     * so all existing rows stay home and single-currency behaviour is unchanged.
     *
     * For foreign documents the existing *_cents columns hold the FOREIGN amount
     * (the customer/vendor transacts in their own currency); fx_rate is the rate
     * locked at entry and home_*_cents caches the home value at that rate. The GL
     * is the home view; these tables are the native/foreign view.
     *
     * A contact transacts in exactly one currency (QuickBooks rule), so its
     * cached ar_balance_cents / ap_balance_cents are in that contact's currency.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->char('currency_code', 3)->nullable()->after('ap_balance_cents');
        });

        foreach (['invoices', 'bills', 'credit_memos'] as $documentTable) {
            Schema::table($documentTable, function (Blueprint $table) {
                $table->char('currency_code', 3)->nullable()->after('total_cents');
                $table->decimal('fx_rate', 18, 8)->nullable()->after('currency_code');
                $table->bigInteger('home_total_cents')->nullable()->after('fx_rate');
            });
        }

        foreach (['customer_receipts', 'bill_payments', 'cheques', 'deposits'] as $paymentTable) {
            Schema::table($paymentTable, function (Blueprint $table) {
                $table->char('currency_code', 3)->nullable()->after('amount_cents');
                $table->decimal('fx_rate', 18, 8)->nullable()->after('currency_code');
                $table->bigInteger('home_amount_cents')->nullable()->after('fx_rate');
            });
        }
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('currency_code');
        });

        foreach (['invoices', 'bills', 'credit_memos'] as $documentTable) {
            Schema::table($documentTable, function (Blueprint $table) {
                $table->dropColumn(['currency_code', 'fx_rate', 'home_total_cents']);
            });
        }

        foreach (['customer_receipts', 'bill_payments', 'cheques', 'deposits'] as $paymentTable) {
            Schema::table($paymentTable, function (Blueprint $table) {
                $table->dropColumn(['currency_code', 'fx_rate', 'home_amount_cents']);
            });
        }
    }
};
