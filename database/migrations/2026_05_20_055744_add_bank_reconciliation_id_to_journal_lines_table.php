<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreignId('bank_reconciliation_id')
                ->nullable()
                ->after('cleared_at')
                ->constrained('bank_reconciliations')
                ->nullOnDelete();

            $table->index(['account_id', 'bank_reconciliation_id']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'bank_reconciliation_id']);
            $table->dropForeign(['bank_reconciliation_id']);
            $table->dropColumn('bank_reconciliation_id');
        });
    }
};
