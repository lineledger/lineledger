<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exchange rates, stored as "quote units per 1 base unit" on a given date.
     * The app stores foreign→home pairs (base = foreign, quote = home), so the
     * rate is directly usable as home-per-foreign when converting documents.
     *
     *   - company_id null  = a global rate fetched from the provider (shared)
     *   - company_id set   = a manual override entered for one company; it wins
     *                        over the global rate in {@see ExchangeRateService}.
     *
     * Lookups take the most recent rate on or before the as-of date, mirroring
     * how balances use entry_date <= date.
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->char('base_code', 3);
            $table->char('quote_code', 3);
            $table->decimal('rate', 18, 8);
            $table->date('rate_date');
            $table->string('source')->default('manual');
            $table->string('provider')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'base_code', 'quote_code', 'rate_date', 'source'], 'exchange_rates_pair_unique');
            $table->index(['base_code', 'quote_code', 'rate_date'], 'exchange_rates_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
