<?php

namespace App\Enums;

/**
 * The REST resources exposed by the v1 API. The case value is the route URI
 * segment, and resource-level scopes are expressed as `{resource}:{action}`
 * (e.g. `invoices:write`). Each resource belongs to one coarse {@see ApiAbility}
 * domain; a domain grant (e.g. `sales:write`) is a superset that satisfies every
 * resource scope under it — see CompanyApiKey::hasAbility.
 */
enum ApiResource: string
{
    // Sales / AR
    case Customers = 'customers';
    case SalesOrders = 'sales-orders';
    case Invoices = 'invoices';
    case Receipts = 'receipts';
    case CreditMemos = 'credit-memos';

    // Purchases / AP
    case Vendors = 'vendors';
    case Employees = 'employees';
    case Bills = 'bills';
    case BillPayments = 'bill-payments';

    // Banking
    case Cheques = 'cheques';
    case Deposits = 'deposits';
    case Transfers = 'transfers';
    case BankReconciliations = 'bank-reconciliations';

    // Accounting / GL
    case Accounts = 'accounts';
    case JournalEntries = 'journal-entries';
    case Assets = 'assets';
    case AssetCategories = 'asset-categories';

    // Inventory
    case Items = 'items';
    case StockAdjustments = 'stock-adjustments';

    // Tax
    case TaxCodes = 'tax-codes';
    case TaxAgencies = 'tax-agencies';
    case TaxReturns = 'tax-returns';
    case TaxReturnPayments = 'tax-return-payments';

    // Settings / Lists
    case PaymentTerms = 'payment-terms';
    case PaymentMethods = 'payment-methods';

    /**
     * The coarse domain (an ApiAbility prefix, e.g. "sales") this resource
     * belongs to. A domain grant is a superset of every resource scope under it.
     */
    public function domain(): string
    {
        return match ($this) {
            self::Customers, self::SalesOrders, self::Invoices, self::Receipts, self::CreditMemos => 'sales',
            self::Vendors, self::Employees, self::Bills, self::BillPayments => 'purchases',
            self::Cheques, self::Deposits, self::Transfers, self::BankReconciliations => 'banking',
            self::Accounts, self::JournalEntries, self::Assets, self::AssetCategories => 'accounting',
            self::Items, self::StockAdjustments => 'inventory',
            self::TaxCodes, self::TaxAgencies, self::TaxReturns, self::TaxReturnPayments => 'tax',
            self::PaymentTerms, self::PaymentMethods => 'settings',
        };
    }

    /**
     * Human-friendly label for the settings UI (e.g. "Sales orders").
     */
    public function label(): string
    {
        return ucfirst(str_replace('-', ' ', $this->value));
    }

    /**
     * Every resource-level scope string (`{resource}:read` and `:write`),
     * useful for validating the abilities a key may be granted.
     *
     * @return array<int, string>
     */
    public static function scopeValues(): array
    {
        $scopes = [];

        foreach (self::cases() as $resource) {
            $scopes[] = "{$resource->value}:read";
            $scopes[] = "{$resource->value}:write";
        }

        return $scopes;
    }
}
