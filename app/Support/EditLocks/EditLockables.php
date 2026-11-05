<?php

namespace App\Support\EditLocks;

use App\Enums\BillType;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\BankRule;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Budget;
use App\Models\Cheque;
use App\Models\Classification;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Estimate;
use App\Models\Expense;
use App\Models\FormStyle;
use App\Models\Fund;
use App\Models\Grant;
use App\Models\Invoice;
use App\Models\InvoiceTemplate;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\JournalEntry;
use App\Models\JournalEntryTemplate;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipLevel;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\PurchaseOrder;
use App\Models\RecurringDocument;
use App\Models\RecurringJournalEntry;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesReceipt;
use App\Models\TaxAgency;
use App\Models\TaxCode;
use App\Models\TaxReturn;
use App\Models\TimeOffPolicy;
use App\Models\Transfer;
use App\Models\VendorCredit;
use Illuminate\Database\Eloquent\Model;

/**
 * The records that take an edit lock, and the noun each is called in messages
 * ("Jane Doe is editing this invoice").
 *
 * Models with a web edit form or edit dialog belong here; the API middleware
 * locks exactly these. Deliberately left out: bank reconciliations (the
 * reconciliation workspace is out of scope), and stock adjustments and
 * tax-return payments (created and voided only, never edited in the web app).
 */
final class EditLockables
{
    /**
     * @return array<class-string<Model>, string>
     */
    public static function nouns(): array
    {
        return [
            Account::class => 'account',
            Asset::class => 'asset',
            AssetCategory::class => 'asset category',
            BankRule::class => 'bank rule',
            Bill::class => 'bill',
            BillPayment::class => 'bill payment',
            Budget::class => 'budget',
            Cheque::class => 'cheque',
            Classification::class => 'class',
            Contact::class => 'contact',
            CreditMemo::class => 'credit memo',
            CustomerReceipt::class => 'receipt',
            Deposit::class => 'deposit',
            Donation::class => 'donation',
            DonationReceipt::class => 'donation receipt',
            Estimate::class => 'estimate',
            Expense::class => 'expense',
            FormStyle::class => 'form style',
            Fund::class => 'fund',
            Grant::class => 'grant',
            Invoice::class => 'invoice',
            InvoiceTemplate::class => 'invoice template',
            Item::class => 'item',
            ItemCategory::class => 'item category',
            JournalEntry::class => 'journal entry',
            JournalEntryTemplate::class => 'journal entry template',
            Location::class => 'location',
            Member::class => 'member',
            MembershipLevel::class => 'membership level',
            PaymentMethod::class => 'payment method',
            PaymentTerm::class => 'payment term',
            PayrollSchedule::class => 'payroll schedule',
            PayRun::class => 'pay run',
            PurchaseOrder::class => 'purchase order',
            RecurringDocument::class => 'recurring transaction',
            RecurringJournalEntry::class => 'recurring journal entry',
            Role::class => 'role',
            SalesOrder::class => 'sales order',
            SalesReceipt::class => 'sales receipt',
            TaxAgency::class => 'tax agency',
            TaxCode::class => 'tax code',
            TaxReturn::class => 'tax return',
            TimeOffPolicy::class => 'time-off policy',
            Transfer::class => 'transfer',
            VendorCredit::class => 'vendor credit',
        ];
    }

    public static function supports(Model|string $model): bool
    {
        return array_key_exists(is_string($model) ? $model : $model::class, self::nouns());
    }

    /**
     * The translated noun for one record, refined where one model plays
     * several roles (a Bill is a bill or a reimbursement; a Contact a
     * customer, vendor, employee…).
     */
    public static function noun(Model $record): string
    {
        $noun = match (true) {
            $record instanceof Bill => $record->getAttribute('bill_type') === BillType::Reimbursement ? 'reimbursement' : 'bill',
            $record instanceof Contact => match (true) {
                (bool) $record->is_employee => 'employee',
                (bool) $record->is_customer => 'customer',
                (bool) $record->is_vendor => 'vendor',
                default => 'contact',
            },
            default => self::nouns()[$record::class] ?? 'record',
        };

        return __($noun);
    }
}
