<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every table that carries dimension-taggable lines: the GL itself plus each
     * source-document line table. Columns are nullable so existing rows and the
     * no-dimension path are unaffected.
     *
     * @var list<string>
     */
    private array $tables = [
        'journal_lines',
        'invoice_lines',
        'bill_lines',
        'cheque_lines',
        'credit_memo_lines',
        'estimate_lines',
        'sales_order_lines',
        'stock_adjustment_lines',
        'deposit_lines',
        'recurring_document_lines',
    ];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('class_id')->nullable()->constrained('classifications')->nullOnDelete();
                $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('class_id');
                $table->dropConstrainedForeignId('location_id');
            });
        }
    }
};
