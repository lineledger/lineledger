<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-currency is an opt-in switch per company. While off, every code path
     * falls back to the home currency and behaves exactly as before. The two FX
     * P&L accounts are created lazily the first time a foreign currency is enabled
     * (see EnableCompanyCurrency), so single-currency companies stay uncluttered.
     *
     *   - exchange_gain_loss_account_id   realized FX on settlement (permanent)
     *   - unrealized_gain_loss_account_id period-end revaluation (reverses next day)
     */
    public function up(): void
    {
        // Plain nullable id columns rather than FKs: a constraint here would point
        // companies → accounts while accounts already points accounts → companies,
        // a circular dependency that trips up migrate:fresh's drop ordering. The
        // referenced FX accounts are is_system and never deleted, so the integrity
        // guarantee a constraint would add is not needed.
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('multicurrency_enabled')->default(false)->after('currency_code');
            $table->unsignedBigInteger('exchange_gain_loss_account_id')->nullable()->after('multicurrency_enabled');
            $table->unsignedBigInteger('unrealized_gain_loss_account_id')->nullable()->after('exchange_gain_loss_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['multicurrency_enabled', 'exchange_gain_loss_account_id', 'unrealized_gain_loss_account_id']);
        });
    }
};
