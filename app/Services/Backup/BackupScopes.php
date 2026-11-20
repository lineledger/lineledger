<?php

namespace App\Services\Backup;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Estimate;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesReceipt;
use App\Models\StockAdjustment;
use App\Models\TaxReturn;
use App\Models\VendorCredit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Which rows of a {@see BackupTableRegistry} table belong to one organization.
 *
 * Shared by everything that reads an organization's rows table by table: the
 * exporter writing a bundle, and the backup verifier reading the same
 * organization (or its restored copy) back to compare against that bundle.
 * One definition means the two can't drift apart and select different rows.
 *
 * A table with a `company_id` column filters on it directly; `companies` on
 * its own `id`. Line tables carry no `company_id` and belong to an
 * organization only through their parent document, so they filter through the
 * parent's company-scoped ids ({@see self::CHILD_TABLES}). Global scopes are
 * always dropped, so the explicit filter is the only tenancy clause and the
 * `current_company` binding doesn't matter.
 */
final class BackupScopes
{
    /**
     * Registry tables without a `company_id`: table => [parent FK column, parent model].
     *
     * @var array<string, array{0: string, 1: class-string<Model>}>
     */
    public const CHILD_TABLES = [
        'journal_lines' => ['journal_entry_id', JournalEntry::class],
        'invoice_lines' => ['invoice_id', Invoice::class],
        'item_components' => ['item_id', Item::class],
        'sales_receipt_lines' => ['sales_receipt_id', SalesReceipt::class],
        'estimate_lines' => ['estimate_id', Estimate::class],
        'sales_order_lines' => ['sales_order_id', SalesOrder::class],
        'credit_memo_lines' => ['credit_memo_id', CreditMemo::class],
        'bill_lines' => ['bill_id', Bill::class],
        'purchase_order_lines' => ['purchase_order_id', PurchaseOrder::class],
        'vendor_credit_lines' => ['vendor_credit_id', VendorCredit::class],
        'cheque_lines' => ['cheque_id', Cheque::class],
        'expense_lines' => ['expense_id', Expense::class],
        'tax_return_lines' => ['tax_return_id', TaxReturn::class],
        'stock_adjustment_lines' => ['stock_adjustment_id', StockAdjustment::class],
        'receipt_applications' => ['customer_receipt_id', CustomerReceipt::class],
        'bill_payment_applications' => ['bill_payment_id', BillPayment::class],
        'deposit_lines' => ['deposit_id', Deposit::class],
    ];

    /**
     * The organization's rows of `$table`, through `$modelClass`, with global
     * scopes dropped.
     *
     * @param  class-string<Model>  $modelClass
     * @return Builder<Model>
     */
    public static function query(string $modelClass, string $table, int $companyId): Builder
    {
        $query = $modelClass::query()->withoutGlobalScopes();

        self::apply($query, $table, $companyId);

        return $query;
    }

    /**
     * Constrain `$query` (on `$table`) to one organization's rows.
     *
     * @param  Builder<Model>  $query
     */
    public static function apply(Builder $query, string $table, int $companyId): void
    {
        if (isset(self::CHILD_TABLES[$table])) {
            [$foreignKey, $parentModel] = self::CHILD_TABLES[$table];

            $query->whereIn(
                $foreignKey,
                $parentModel::query()
                    ->withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->select('id'),
            );

            return;
        }

        if ($table === 'companies') {
            $query->where('id', $companyId);

            return;
        }

        if (Schema::hasColumn($table, 'company_id')) {
            $query->where('company_id', $companyId);

            return;
        }

        throw new RuntimeException(
            "Table [{$table}] has no company_id column and no entry in BackupScopes::CHILD_TABLES."
        );
    }
}
