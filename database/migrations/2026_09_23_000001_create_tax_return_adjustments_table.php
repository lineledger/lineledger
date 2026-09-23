<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tax_return_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_return_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('memo')->nullable();
            $table->unsignedSmallInteger('line_order')->default(0);
            $table->timestamps();

            $table->index(['tax_return_id', 'line_order']);
        });

        Schema::table('tax_returns', function (Blueprint $table) {
            $table->bigInteger('other_adjustments_cents')->default(0)->after('paid_cents');
            $table->foreignId('adjustment_journal_entry_id')->nullable()->after('net_cents')
                ->constrained('journal_entries')->nullOnDelete();
            $table->json('reconciliation')->nullable()->after('excluded_journal_line_ids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tax_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adjustment_journal_entry_id');
            $table->dropColumn(['other_adjustments_cents', 'reconciliation']);
        });

        Schema::dropIfExists('tax_return_adjustments');
    }
};
