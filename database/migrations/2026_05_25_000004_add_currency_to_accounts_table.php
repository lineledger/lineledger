<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An account's transacting currency. NULL = home currency, which keeps every
     * existing account (and all equity/income/expense/tax accounts) on the home
     * currency with no backfill. Non-null only on foreign Bank / CreditCard
     * accounts and the per-currency foreign AR/AP control accounts.
     *
     * balance_cents stays in HOME cents regardless; the foreign face value of a
     * foreign account is derived on demand from the foreign_* memo columns.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->char('currency_code', 3)->nullable()->after('normal_balance');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('currency_code');
        });
    }
};
