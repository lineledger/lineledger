<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CashFlowActivity;
use App\Enums\NormalBalance;
use App\Observers\AccountObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id',
    'parent_id',
    'report_section_id',
    'cash_flow_activity',
    'code',
    'name',
    'type',
    'subtype',
    'gifi_code',
    'default_tax_code_id',
    'normal_balance',
    'currency_code',
    'is_system',
    'is_active',
    'use_in_transfers',
    'description',
    'balance_cents',
])]
#[ObservedBy(AccountObserver::class)]
class Account extends Model
{
    use BelongsToCompany, SoftDeletes;

    /**
     * The Equity-subtype account that balances opening-balance entries. For-profit
     * charts name it "Opening Balance Equity"; non-profit charts use net-asset
     * terminology. The account is identified by name (no dedicated flag), so every
     * resolver must accept BOTH names — use {@see self::OPENING_BALANCE_NAMES}.
     */
    public const OPENING_BALANCE_EQUITY_NAME = 'Opening Balance Equity';

    public const OPENING_BALANCE_NET_ASSETS_NAME = 'Opening Balance Net Assets';

    /** @var list<string> Either name the opening-balance balancing account may carry. */
    public const OPENING_BALANCE_NAMES = [
        self::OPENING_BALANCE_EQUITY_NAME,
        self::OPENING_BALANCE_NET_ASSETS_NAME,
    ];

    /**
     * The opening-balance balancing account name for a company, by org type:
     * "Opening Balance Net Assets" for non-profits, otherwise the equity name.
     */
    public static function openingBalanceNameFor(Company $company): string
    {
        return $company->organization_type?->isNonProfit()
            ? self::OPENING_BALANCE_NET_ASSETS_NAME
            : self::OPENING_BALANCE_EQUITY_NAME;
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * @return BelongsTo<ReportSection, $this>
     */
    public function reportSection(): BelongsTo
    {
        return $this->belongsTo(ReportSection::class);
    }

    /**
     * @return BelongsTo<TaxCode, $this>
     */
    public function defaultTaxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'default_tax_code_id');
    }

    public function isLeaf(): bool
    {
        return ! $this->children()->exists();
    }

    /**
     * Restrict to accounts that can back an item's default account or a
     * sales line (invoice / credit memo). Any asset, liability, income, or
     * expense account qualifies, except the AR/AP control accounts and
     * Undeposited Funds — posting line items directly to those double-counts
     * and breaks the GL-driven AR reports.
     *
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    /**
     * The system control account employee reimbursements settle against. Seeded
     * with the employees feature, so it may be absent — callers must handle null.
     *
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function scopeEmployeeReimbursementsPayable(Builder $query): Builder
    {
        return $query
            ->where('is_system', true)
            ->where('subtype', AccountSubtype::CurrentLiability->value)
            ->where('name', 'Employee Reimbursements Payable');
    }

    public function scopeSelectableForItemAccount(Builder $query): Builder
    {
        return $query
            ->whereIn('type', [
                AccountType::Asset->value,
                AccountType::Liability->value,
                AccountType::Income->value,
                AccountType::Expense->value,
            ])
            ->whereNotIn('subtype', [
                AccountSubtype::AccountsReceivable->value,
                AccountSubtype::AccountsPayable->value,
                AccountSubtype::UndepositedFunds->value,
            ]);
    }

    /**
     * Whether this account is denominated in a foreign (non-home) currency.
     * A null currency_code means the account is in the company home currency.
     */
    public function isForeignCurrency(): bool
    {
        return $this->currency_code !== null;
    }

    /**
     * The account's balance in its OWN (foreign) currency minor units, derived
     * from the foreign memo columns. Only meaningful for foreign accounts; home
     * accounts return 0 here (their balance lives in {@see balance_cents}).
     * Signed following the account's normal balance, matching recomputeBalance().
     */
    public function foreignBalanceCents(): int
    {
        $totals = $this->journalLines()
            ->where('is_posted', true)
            ->selectRaw('COALESCE(SUM(foreign_debit_cents), 0) as debits, COALESCE(SUM(foreign_credit_cents), 0) as credits')
            ->first();

        $debits = (int) ($totals->debits ?? 0);
        $credits = (int) ($totals->credits ?? 0);

        return $this->normal_balance === NormalBalance::Debit
            ? $debits - $credits
            : $credits - $debits;
    }

    /**
     * Recompute this account's cached balance from its posted journal lines.
     * Stored as a signed cents value following the account's normal balance:
     * positive = balance is on the natural side; negative = contra balance.
     */
    public function recomputeBalance(): int
    {
        $totals = $this->journalLines()
            ->where('is_posted', true)
            ->selectRaw('COALESCE(SUM(debit_cents), 0) as debits, COALESCE(SUM(credit_cents), 0) as credits')
            ->first();

        $debits = (int) ($totals->debits ?? 0);
        $credits = (int) ($totals->credits ?? 0);

        $balance = $this->normal_balance === NormalBalance::Debit
            ? $debits - $credits
            : $credits - $debits;

        $this->forceFill(['balance_cents' => $balance])->saveQuietly();

        return $balance;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'subtype' => AccountSubtype::class,
            'cash_flow_activity' => CashFlowActivity::class,
            'normal_balance' => NormalBalance::class,
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'use_in_transfers' => 'boolean',
            'balance_cents' => 'integer',
        ];
    }
}
