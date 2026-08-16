<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the nullable fund dimension to every table that already carries the
     * class/location dimensions (the GL plus each source-document line table and
     * budgets/payroll profiles). Nullable so existing rows and the no-fund path
     * are unaffected.
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
        'recurring_journal_entry_lines',
        'purchase_order_lines',
        'vendor_credit_lines',
        'budgets',
        'employee_payroll_profiles',
    ];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('fund_id')->nullable()->constrained('funds')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('fund_id');
            });
        }
    }
};
