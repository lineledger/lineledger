<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-currency memo columns. The general ledger always balances in the
     * company's HOME currency, so debit_cents / credit_cents stay home cents and
     * every existing balance/report query is untouched. These columns carry the
     * original foreign amount alongside, for foreign-denominated lines only:
     *
     *   - currency_code        null = home-currency line (the common case)
     *   - fx_rate              home units per 1 foreign unit, locked at posting
     *   - foreign_debit_cents  the original foreign amount on the debit side
     *   - foreign_credit_cents the original foreign amount on the credit side
     *
     * Deliberately NOT indexed — they are never summed in the balance hot path
     * (only surfaced per-account/per-contact for foreign balance columns).
     */
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->char('currency_code', 3)->nullable()->after('credit_cents');
            $table->decimal('fx_rate', 18, 8)->nullable()->after('currency_code');
            $table->bigInteger('foreign_debit_cents')->default(0)->after('fx_rate');
            $table->bigInteger('foreign_credit_cents')->default(0)->after('foreign_debit_cents');
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'fx_rate', 'foreign_debit_cents', 'foreign_credit_cents']);
        });
    }
};
