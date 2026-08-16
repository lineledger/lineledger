<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-line discount columns. line_discount_cents is the canonical amount used by
     * the totals math and GL posting (it reduces the stored net line_subtotal_cents);
     * line_discount_pct only remembers a percent entry to repopulate the input on edit.
     *
     * @var list<string>
     */
    private array $discountTables = [
        'invoice_lines',
        'estimate_lines',
        'sales_order_lines',
        'credit_memo_lines',
        'bill_lines',
        'recurring_document_lines',
    ];

    /**
     * Per-line service date (when work was performed vs. the document date). Sales-side
     * documents only — bills do not carry a service date.
     *
     * @var list<string>
     */
    private array $serviceDateTables = [
        'invoice_lines',
        'estimate_lines',
        'sales_order_lines',
        'credit_memo_lines',
        'recurring_document_lines',
    ];

    public function up(): void
    {
        foreach ($this->discountTables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->integer('line_discount_cents')->default(0)->after('unit_price_cents');
                $table->decimal('line_discount_pct', 7, 4)->nullable()->after('line_discount_cents');
            });
        }

        foreach ($this->serviceDateTables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->date('service_date')->nullable()->after('description');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->discountTables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn(['line_discount_cents', 'line_discount_pct']);
            });
        }

        foreach ($this->serviceDateTables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('service_date');
            });
        }
    }
};
