<?php

namespace App\Enums;

use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\SalesReceipt;
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

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Invoice => Invoice::class,
            self::SalesReceipt => SalesReceipt::class,
            self::CustomerReceipt => CustomerReceipt::class,
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
        };
    }

    /** Lower-case, for use inside a sentence. */
    public function noun(): string
    {
        return match ($this) {
            self::Invoice => __('invoice'),
            self::SalesReceipt => __('sales receipt'),
            self::CustomerReceipt => __('receipt'),
        };
    }

    public function numberColumn(): string
    {
        return match ($this) {
            self::Invoice => 'invoice_no',
            self::SalesReceipt => 'sales_receipt_no',
            self::CustomerReceipt => 'receipt_no',
        };
    }

    public function dateColumn(): string
    {
        return match ($this) {
            self::Invoice => 'invoice_date',
            self::SalesReceipt, self::CustomerReceipt => 'receipt_date',
        };
    }

    /**
     * The status a draft of this type carries; every other status is posted
     * or voided and owns a journal entry.
     */
    public function draftStatus(): string
    {
        return match ($this) {
            self::Invoice => InvoiceStatus::Draft->value,
            self::SalesReceipt => SalesReceiptStatus::Draft->value,
            self::CustomerReceipt => ReceiptStatus::Draft->value,
        };
    }

    public function indexRoute(): string
    {
        return match ($this) {
            self::Invoice => 'invoices.index',
            self::SalesReceipt => 'sales-receipts.index',
            self::CustomerReceipt => 'receipts.index',
        };
    }

    public function showRoute(): string
    {
        return match ($this) {
            self::Invoice => 'invoices.show',
            self::SalesReceipt => 'sales-receipts.show',
            self::CustomerReceipt => 'receipts.show',
        };
    }

    /** The show route's model parameter. */
    public function routeParameter(): string
    {
        return match ($this) {
            self::Invoice => 'invoice',
            self::SalesReceipt, self::CustomerReceipt => 'receipt',
        };
    }

    public function auditAction(): AuditAction
    {
        return match ($this) {
            self::Invoice => AuditAction::InvoiceDeleted,
            self::SalesReceipt => AuditAction::SalesReceiptDeleted,
            self::CustomerReceipt => AuditAction::CustomerReceiptDeleted,
        };
    }
}
