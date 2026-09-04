<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\AccountSubtype;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'company_id', 'parent_id', 'display_name', 'company_name', 'account_no', 'first_name', 'last_name', 'job_title', 'employee_id',
    'email', 'phone', 'mobile', 'tax_number', 'track_1099', 'track_t4a',
    'is_customer', 'is_vendor', 'is_employee', 'is_donor', 'donor_type', 'is_member', 'is_other_name',
    'billing_line1', 'billing_line2', 'billing_city', 'billing_region', 'billing_postal_code', 'billing_country',
    'shipping_line1', 'shipping_line2', 'shipping_city', 'shipping_region', 'shipping_postal_code', 'shipping_country',
    'default_terms_id', 'default_tax_code_id', 'default_income_account_id', 'default_expense_account_id',
    'preferred_payment_method_id', 'credit_limit_cents',
    'currency_code',
    'notes', 'is_active', 'invoice_emails_enabled', 'reminder_emails_enabled',
])]
class Contact extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait, BelongsToCompany, HasFactory, Notifiable, SoftDeletes;

    /**
     * The optional employee-portal password hash must never leak through
     * serialization (API responses, Livewire payloads, logs).
     *
     * @var list<string>
     */
    protected $hidden = ['portal_password'];

    /**
     * Mirrors the column defaults so a contact reads as opted out in memory too,
     * not only once it has been reloaded from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'invoice_emails_enabled' => false,
        'reminder_emails_enabled' => false,
    ];

    /**
     * The `customer` guard authenticates Contacts. Password-based sign-in (the
     * employee self-service portal) checks against the optional portal
     * password; contacts without one can only sign in via magic link.
     */
    public function getAuthPassword(): ?string
    {
        return $this->portal_password;
    }

    /**
     * Customers eligible to sign in to the payment portal: an active contact
     * flagged as a customer with a usable email address.
     *
     * @param  Builder<Contact>  $query
     */
    public function scopePortalEligible(Builder $query): void
    {
        $query->where('is_customer', true)
            ->where('is_active', true)
            ->whereNotNull('email');
    }

    /**
     * Employees eligible to sign in to the self-service ("my-pay") portal: an
     * active contact flagged as an employee, with a usable email address and an
     * active payroll profile (so there is pay data to show). Mirrors
     * {@see scopePortalEligible}; the two audiences are kept distinct.
     *
     * @param  Builder<Contact>  $query
     */
    public function scopeEmployeePortalEligible(Builder $query): void
    {
        $query->where('is_employee', true)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->whereHas('payrollProfile', fn (Builder $q) => $q->where('is_active', true));
    }

    /**
     * QuickBooks-style "Other names": one-time payees that are not a customer,
     * vendor or employee. Backs the Settings → Lists page and the payee picker.
     *
     * @param  Builder<Contact>  $query
     */
    public function scopeOtherNames(Builder $query): void
    {
        $query->where('is_other_name', true);
    }

    /**
     * Whether the contact holds a directory role (customer, vendor or employee)
     * — the roles with their own list page, as opposed to the Other name /
     * donor / member flags that only qualify one.
     */
    public function hasDirectoryRole(): bool
    {
        return (bool) $this->is_customer || (bool) $this->is_vendor || (bool) $this->is_employee;
    }

    /**
     * The parent contact, when this is a sub-customer / job.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Display name qualified by the parent for a sub-customer ("Parent : Job"),
     * so jobs are distinguishable wherever a flat name would be ambiguous.
     */
    public function qualifiedName(): string
    {
        return $this->parent_id !== null && $this->parent !== null
            ? $this->parent->display_name.' : '.$this->display_name
            : $this->display_name;
    }

    /**
     * @return BelongsTo<PaymentTerm, $this>
     */
    public function defaultTerms(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'default_terms_id');
    }

    /**
     * @return BelongsTo<TaxCode, $this>
     */
    public function defaultTaxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'default_tax_code_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function defaultIncomeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_income_account_id');
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function preferredPaymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'preferred_payment_method_id');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<CreditMemo, $this>
     */
    public function creditMemos(): HasMany
    {
        return $this->hasMany(CreditMemo::class);
    }

    /**
     * @return HasMany<CustomerReceipt, $this>
     */
    public function customerReceipts(): HasMany
    {
        return $this->hasMany(CustomerReceipt::class);
    }

    /**
     * Cheques written to this contact that refund a credit memo. These debit
     * Accounts Receivable, so they raise the AR balance back toward zero.
     *
     * @return HasMany<Cheque, $this>
     */
    public function refundCheques(): HasMany
    {
        return $this->hasMany(Cheque::class, 'payee_contact_id')
            ->whereNotNull('credit_memo_id');
    }

    /**
     * @return HasMany<Bill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    /**
     * The employee's payroll setup (1:1). Present only for employees enrolled
     * in payroll.
     *
     * @return HasOne<EmployeePayrollProfile, $this>
     */
    public function payrollProfile(): HasOne
    {
        return $this->hasOne(EmployeePayrollProfile::class);
    }

    /**
     * @return HasMany<BillPayment, $this>
     */
    public function billPayments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    /**
     * @return HasMany<VendorCredit, $this>
     */
    public function vendorCredits(): HasMany
    {
        return $this->hasMany(VendorCredit::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    /**
     * Whether this contact's transacting currency can still be changed: only
     * while it has no invoices, bills, receipts, or payments on the books.
     */
    public function canChangeCurrency(): bool
    {
        return ! $this->invoices()->exists()
            && ! $this->bills()->exists()
            && ! $this->customerReceipts()->exists()
            && ! $this->billPayments()->exists();
    }

    /**
     * Recompute the cached AR balance straight from the general ledger: the customer's
     * net balance on the Accounts Receivable control account(s). This is the single
     * source of truth that the AR Aging report and Contact Statement also use, so the
     * Customers list can never silently drift from them. Reading the GL also means an
     * invoice settled via {@see Invoice::$reconciled_cents} (a credit memo or write-off
     * applied with no new GL) is counted exactly once — never double-counted against the
     * credit memo itself. Includes voided entries: voiding posts a reversal that nets the
     * original, matching AR Aging. Overpayments yield a negative (credit) balance.
     */
    public function recomputeArBalance(): int
    {
        $arAccountIds = Account::query()
            ->where('company_id', $this->company_id)
            ->where('subtype', AccountSubtype::AccountsReceivable->value)
            ->pluck('id');

        $balance = $arAccountIds->isEmpty() ? 0 : (int) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $this->company_id)
            ->where('je.is_posted', true)
            ->whereIn('jl.account_id', $arAccountIds)
            ->where('jl.contact_id', $this->id)
            ->sum(DB::raw('jl.debit_cents - jl.credit_cents'));

        $this->forceFill(['ar_balance_cents' => $balance])->saveQuietly();

        return $balance;
    }

    /**
     * Recompute the cached AP balance straight from the general ledger: the vendor's net
     * balance on the Accounts Payable control account(s). AP is a liability (credit-normal),
     * so the balance is summed as credit − debit (the opposite sign from AR). Reading the GL
     * is the single source of truth the AP Aging report and Vendor Statement also use, so the
     * Vendors list can never silently drift, and a bill settled via {@see Bill::$reconciled_cents}
     * (a vendor credit or write-off applied with no new GL) is counted exactly once. Includes
     * voided entries (their reversals net them), matching AP Aging.
     */
    public function recomputeApBalance(): int
    {
        $apAccountIds = Account::query()
            ->where('company_id', $this->company_id)
            ->where('subtype', AccountSubtype::AccountsPayable->value)
            ->pluck('id');

        $balance = $apAccountIds->isEmpty() ? 0 : (int) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $this->company_id)
            ->where('je.is_posted', true)
            ->whereIn('jl.account_id', $apAccountIds)
            ->where('jl.contact_id', $this->id)
            ->sum(DB::raw('jl.credit_cents - jl.debit_cents'));

        $this->forceFill(['ap_balance_cents' => $balance])->saveQuietly();

        return $balance;
    }

    /**
     * @return array<string, string>
     */
    /**
     * @return HasMany<DonationReceipt, $this>
     */
    public function donationReceipts(): HasMany
    {
        return $this->hasMany(DonationReceipt::class);
    }

    /**
     * @return HasOne<Member, $this>
     */
    public function member(): HasOne
    {
        return $this->hasOne(Member::class);
    }

    protected function casts(): array
    {
        return [
            'is_customer' => 'boolean',
            'is_vendor' => 'boolean',
            'is_employee' => 'boolean',
            'is_donor' => 'boolean',
            'is_member' => 'boolean',
            'is_other_name' => 'boolean',
            'is_active' => 'boolean',
            'invoice_emails_enabled' => 'boolean',
            'reminder_emails_enabled' => 'boolean',
            // Retired in favour of reminder_emails_enabled, but still cast so backup
            // bundles keep exporting it as a bool. See the 2026_07_09 migration.
            'reminders_muted' => 'boolean',
            'track_1099' => 'boolean',
            'portal_password' => 'hashed',
            'ar_balance_cents' => 'integer',
            'ap_balance_cents' => 'integer',
            'credit_limit_cents' => 'integer',
        ];
    }
}
