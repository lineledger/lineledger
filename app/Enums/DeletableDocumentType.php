<?php

namespace App\Enums;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Donation;
use App\Models\Estimate;
use App\Models\Expense;
use App\Models\Grant;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesReceipt;
use App\Models\StockAdjustment;
use App\Models\TaxReturnPayment;
use App\Models\Transfer;
use App\Models\VendorCredit;
use Illuminate\Database\Eloquent\Model;

/**
 * The transaction types Delete covers. The backed value is what a tombstone
 * keeps (deleted_transactions.document_type): a stable string, not a class
 * name, so a refactor never orphans the record. See TransactionDeleter.
 */
enum DeletableDocumentType: string
{
    case Invoice = 'invoice';
    case SalesReceipt = 'sales_receipt';
    case CustomerReceipt = 'customer_receipt';
    case CreditMemo = 'credit_memo';
    case Bill = 'bill';
    case BillPayment = 'bill_payment';
    case VendorCredit = 'vendor_credit';
    case Expense = 'expense';
    case Cheque = 'cheque';
    case Deposit = 'deposit';
    case Transfer = 'transfer';
    case JournalEntry = 'journal_entry';
    case Estimate = 'estimate';
    case SalesOrder = 'sales_order';
    case PurchaseOrder = 'purchase_order';
    case StockAdjustment = 'stock_adjustment';
    case TaxReturnPayment = 'tax_return_payment';
    case Donation = 'donation';
    case Grant = 'grant';

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Invoice => Invoice::class,
            self::SalesReceipt => SalesReceipt::class,
            self::CustomerReceipt => CustomerReceipt::class,
            self::CreditMemo => CreditMemo::class,
            self::Bill => Bill::class,
            self::BillPayment => BillPayment::class,
            self::VendorCredit => VendorCredit::class,
            self::Expense => Expense::class,
            self::Cheque => Cheque::class,
            self::Deposit => Deposit::class,
            self::Transfer => Transfer::class,
            self::JournalEntry => JournalEntry::class,
            self::Estimate => Estimate::class,
            self::SalesOrder => SalesOrder::class,
            self::PurchaseOrder => PurchaseOrder::class,
            self::StockAdjustment => StockAdjustment::class,
            self::TaxReturnPayment => TaxReturnPayment::class,
            self::Donation => Donation::class,
            self::Grant => Grant::class,
        };
    }

    public static function forModel(Model $model): ?self
    {
        foreach (self::cases() as $type) {
            if ($model instanceof ($type->modelClass())) {
                return $type;
            }
        }

        return null;
    }

    public static function forClass(?string $class): ?self
    {
        foreach (self::cases() as $type) {
            if ($type->modelClass() === $class) {
                return $type;
            }
        }

        return null;
    }

    /** Capitalized, for headings and report rows. */
    public function label(): string
    {
        return match ($this) {
            self::Invoice => __('Invoice'),
            self::SalesReceipt => __('Sales receipt'),
            self::CustomerReceipt => __('Receipt'),
            self::CreditMemo => __('Credit memo'),
            self::Bill => __('Bill'),
            self::BillPayment => __('Bill payment'),
            self::VendorCredit => __('Vendor credit'),
            self::Expense => __('Expense'),
            self::Cheque => __('Cheque'),
            self::Deposit => __('Deposit'),
            self::Transfer => __('Transfer'),
            self::JournalEntry => __('Journal entry'),
            self::Estimate => __('Estimate'),
            self::SalesOrder => __('Sales order'),
            self::PurchaseOrder => __('Purchase order'),
            self::StockAdjustment => __('Stock adjustment'),
            self::TaxReturnPayment => __('Tax payment'),
            self::Donation => __('Donation'),
            self::Grant => __('Grant'),
        };
    }

    /** Lower-case, for use inside a sentence. */
    public function noun(): string
    {
        return match ($this) {
            self::Invoice => __('invoice'),
            self::SalesReceipt => __('sales receipt'),
            self::CustomerReceipt => __('receipt'),
            self::CreditMemo => __('credit memo'),
            self::Bill => __('bill'),
            self::BillPayment => __('bill payment'),
            self::VendorCredit => __('vendor credit'),
            self::Expense => __('expense'),
            self::Cheque => __('cheque'),
            self::Deposit => __('deposit'),
            self::Transfer => __('transfer'),
            self::JournalEntry => __('journal entry'),
            self::Estimate => __('estimate'),
            self::SalesOrder => __('sales order'),
            self::PurchaseOrder => __('purchase order'),
            self::StockAdjustment => __('stock adjustment'),
            self::TaxReturnPayment => __('tax payment'),
            self::Donation => __('donation'),
            self::Grant => __('grant'),
        };
    }

    /**
     * The document's number. An expense has none of its own, so its
     * reference stands in.
     */
    public function numberColumn(): string
    {
        return match ($this) {
            self::Invoice => 'invoice_no',
            self::SalesReceipt => 'sales_receipt_no',
            self::CustomerReceipt => 'receipt_no',
            self::CreditMemo => 'credit_memo_no',
            self::Bill => 'bill_no',
            self::BillPayment => 'payment_no',
            self::VendorCredit => 'vendor_credit_no',
            self::Expense => 'reference',
            self::Cheque => 'cheque_no',
            self::Deposit => 'deposit_no',
            self::Transfer => 'transfer_no',
            self::JournalEntry => 'entry_no',
            self::Estimate => 'estimate_no',
            self::SalesOrder => 'order_no',
            self::PurchaseOrder => 'po_no',
            self::StockAdjustment => 'adjustment_no',
            self::TaxReturnPayment => 'payment_no',
            self::Donation => 'donation_no',
            self::Grant => 'grant_no',
        };
    }

    public function dateColumn(): string
    {
        return match ($this) {
            self::Invoice => 'invoice_date',
            self::SalesReceipt, self::CustomerReceipt => 'receipt_date',
            self::CreditMemo => 'credit_memo_date',
            self::Bill => 'bill_date',
            self::BillPayment => 'payment_date',
            self::VendorCredit => 'vendor_credit_date',
            self::Expense => 'expense_date',
            self::Cheque => 'cheque_date',
            self::Deposit => 'deposit_date',
            self::Transfer => 'transfer_date',
            self::JournalEntry => 'entry_date',
            self::Estimate => 'estimate_date',
            self::SalesOrder => 'order_date',
            self::PurchaseOrder => 'po_date',
            self::StockAdjustment => 'adjustment_date',
            self::TaxReturnPayment => 'payment_date',
            self::Donation => 'donation_date',
            self::Grant => 'period_start',
        };
    }

    /**
     * Whether the type posts to the general ledger. Estimates and orders
     * never do: deleting one touches no journal entry.
     */
    public function postsToLedger(): bool
    {
        return ! in_array($this, [self::Estimate, self::SalesOrder, self::PurchaseOrder], true);
    }

    /**
     * The status a draft of this type carries; every other status is posted
     * or voided and owns a journal entry. Null for a type with no status
     * column of that kind (a journal entry tracks is_posted; estimates and
     * orders never post).
     */
    public function draftStatus(): ?string
    {
        return match ($this) {
            self::Invoice => InvoiceStatus::Draft->value,
            self::SalesReceipt => SalesReceiptStatus::Draft->value,
            self::CustomerReceipt => ReceiptStatus::Draft->value,
            self::CreditMemo => CreditMemoStatus::Draft->value,
            self::Bill => BillStatus::Draft->value,
            self::BillPayment => BillPaymentStatus::Draft->value,
            self::VendorCredit => VendorCreditStatus::Draft->value,
            self::Expense => ExpenseStatus::Draft->value,
            self::Cheque => ChequeStatus::Draft->value,
            self::Deposit => DepositStatus::Draft->value,
            self::Transfer => TransferStatus::Draft->value,
            self::TaxReturnPayment => TaxReturnPaymentStatus::Draft->value,
            self::Donation => DonationStatus::Draft->value,
            // A stock adjustment tracks posted_at, and a grant's entry is its
            // award entry: neither fits the integrity check's status/entry pair.
            self::JournalEntry, self::Estimate, self::SalesOrder, self::PurchaseOrder, self::StockAdjustment, self::Grant => null,
        };
    }

    public function indexRoute(): string
    {
        return match ($this) {
            self::Invoice => 'invoices.index',
            self::SalesReceipt => 'sales-receipts.index',
            self::CustomerReceipt => 'receipts.index',
            self::CreditMemo => 'credit-memos.index',
            self::Bill => 'bills.index',
            self::BillPayment => 'bill-payments.index',
            self::VendorCredit => 'vendor-credits.index',
            self::Expense => 'expenses.index',
            self::Cheque => 'cheques.index',
            self::Deposit => 'deposits.index',
            self::Transfer => 'transfers.index',
            self::JournalEntry => 'journal.index',
            self::Estimate => 'estimates.index',
            self::SalesOrder => 'sales-orders.index',
            self::PurchaseOrder => 'purchase-orders.index',
            self::StockAdjustment => 'inventory.adjustments.index',
            self::TaxReturnPayment => 'tax-returns.show',
            self::Donation => 'donations.index',
            self::Grant => 'grants.index',
        };
    }

    /**
     * Where to land after deleting this document: a reimbursement is a bill
     * with its own list.
     */
    public function indexRouteFor(Model $document): string
    {
        if ($this === self::Bill && $document->getAttribute('bill_type') instanceof BillType && $document->getAttribute('bill_type') === BillType::Reimbursement) {
            return 'reimbursements.index';
        }

        return $this->indexRoute();
    }

    /**
     * Parameters the index route needs beyond the company: a tax payment lives
     * on its tax return's page.
     *
     * @return array<string, mixed>
     */
    public function indexRouteParametersFor(Model $document): array
    {
        return $this === self::TaxReturnPayment ? ['tax_return' => $document->getAttribute('tax_return_id')] : [];
    }

    public function showRoute(): string
    {
        return match ($this) {
            self::Invoice => 'invoices.show',
            self::SalesReceipt => 'sales-receipts.show',
            self::CustomerReceipt => 'receipts.show',
            self::CreditMemo => 'credit-memos.show',
            self::Bill => 'bills.show',
            self::BillPayment => 'bill-payments.show',
            self::VendorCredit => 'vendor-credits.show',
            self::Expense => 'expenses.show',
            self::Cheque => 'cheques.show',
            self::Deposit => 'deposits.show',
            self::Transfer => 'transfers.show',
            self::JournalEntry => 'journal.show',
            self::Estimate => 'estimates.show',
            self::SalesOrder => 'sales-orders.show',
            self::PurchaseOrder => 'purchase-orders.show',
            self::StockAdjustment => 'inventory.adjustments.index',
            self::TaxReturnPayment => 'tax-returns.payments.show',
            self::Donation => 'donations.show',
            self::Grant => 'grants.show',
        };
    }

    /** The show route's model parameter. */
    public function routeParameter(): string
    {
        return match ($this) {
            self::Invoice => 'invoice',
            self::SalesReceipt, self::CustomerReceipt => 'receipt',
            self::CreditMemo => 'credit_memo',
            self::Bill => 'bill',
            self::BillPayment => 'payment',
            self::VendorCredit => 'vendor_credit',
            self::Expense => 'expense',
            self::Cheque => 'cheque',
            self::Deposit => 'deposit',
            self::Transfer => 'transfer',
            self::JournalEntry => 'entry',
            self::Estimate => 'estimate',
            self::SalesOrder => 'salesOrder',
            self::PurchaseOrder => 'purchaseOrder',
            self::StockAdjustment => 'stockAdjustment',
            self::TaxReturnPayment => 'payment',
            self::Donation => 'donation',
            self::Grant => 'grant',
        };
    }

    public function auditAction(): AuditAction
    {
        return match ($this) {
            self::Invoice => AuditAction::InvoiceDeleted,
            self::SalesReceipt => AuditAction::SalesReceiptDeleted,
            self::CustomerReceipt => AuditAction::CustomerReceiptDeleted,
            self::CreditMemo => AuditAction::CreditMemoDeleted,
            self::Bill => AuditAction::BillDeleted,
            self::BillPayment => AuditAction::BillPaymentDeleted,
            self::VendorCredit => AuditAction::VendorCreditDeleted,
            self::Expense => AuditAction::ExpenseDeleted,
            self::Cheque => AuditAction::ChequeDeleted,
            self::Deposit => AuditAction::DepositDeleted,
            self::Transfer => AuditAction::TransferDeleted,
            self::JournalEntry => AuditAction::JournalEntryDeleted,
            self::Estimate => AuditAction::EstimateDeleted,
            self::SalesOrder => AuditAction::SalesOrderDeleted,
            self::PurchaseOrder => AuditAction::PurchaseOrderDeleted,
            self::StockAdjustment => AuditAction::StockAdjustmentDeleted,
            self::TaxReturnPayment => AuditAction::TaxReturnPaymentDeleted,
            self::Donation => AuditAction::DonationDeleted,
            self::Grant => AuditAction::GrantDeleted,
        };
    }
}
