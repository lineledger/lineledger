<?php

namespace App\Actions\Accounting;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Support\Currency;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Turn on multi-currency for a company and/or activate a foreign currency.
 *
 * Mirrors QuickBooks: enabling a foreign currency creates a dedicated AR and AP
 * control account for it ("Accounts Receivable (USD)"), and the first time any
 * foreign currency is enabled it lazily creates the two FX P&L accounts so that
 * single-currency companies never carry that clutter.
 *
 * The home currency itself is also recorded as a {@see CompanyCurrency} row so
 * the settings UI and rate fetcher can enumerate every currency uniformly.
 */
class EnableCompanyCurrency
{
    public function handle(Company $company, string $currencyCode): CompanyCurrency
    {
        $currencyCode = mb_strtoupper($currencyCode);

        if (! isset(Currency::selectable()[$currencyCode])) {
            throw new DomainException("Currency [{$currencyCode}] is not available for multi-currency.");
        }

        if ($company->isHomeCurrency($currencyCode)) {
            throw new DomainException('The home currency is always enabled and cannot be added as a foreign currency.');
        }

        return DB::transaction(function () use ($company, $currencyCode): CompanyCurrency {
            $this->ensureEnabled($company);
            $this->ensureHomeCurrencyRow($company);

            $existing = CompanyCurrency::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('currency_code', $currencyCode)
                ->first();

            if ($existing !== null) {
                $existing->forceFill(['is_active' => true])->save();

                return $existing;
            }

            $arAccount = $this->createControlAccount($company, $currencyCode, AccountSubtype::AccountsReceivable);
            $apAccount = $this->createControlAccount($company, $currencyCode, AccountSubtype::AccountsPayable);

            return CompanyCurrency::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'currency_code' => $currencyCode,
                'is_home' => false,
                'is_active' => true,
                'ar_account_id' => $arAccount->id,
                'ap_account_id' => $apAccount->id,
            ]);
        });
    }

    /**
     * Flip the company flag on first use and ensure the two FX P&L accounts exist.
     */
    private function ensureEnabled(Company $company): void
    {
        $dirty = [];

        if (! $company->multicurrency_enabled) {
            $dirty['multicurrency_enabled'] = true;
        }

        if ($company->exchange_gain_loss_account_id === null) {
            $dirty['exchange_gain_loss_account_id'] = $this->createSystemAccount(
                $company,
                AccountSubtype::OtherExpense,
                '7990',
                'Exchange Gain or Loss',
                'Realized foreign exchange gains and losses on settlement.',
            )->id;
        }

        if ($company->unrealized_gain_loss_account_id === null) {
            $dirty['unrealized_gain_loss_account_id'] = $this->createSystemAccount(
                $company,
                AccountSubtype::OtherExpense,
                '7991',
                'Unrealized Gain or Loss',
                'Period-end revaluation of open foreign balances (reversed next period).',
            )->id;
        }

        if ($dirty !== []) {
            $company->forceFill($dirty)->save();
        }
    }

    /**
     * Record the home currency as an is_home CompanyCurrency row (idempotent).
     */
    private function ensureHomeCurrencyRow(Company $company): void
    {
        CompanyCurrency::withoutGlobalScopes()->firstOrCreate(
            [
                'company_id' => $company->id,
                'currency_code' => mb_strtoupper((string) $company->currency_code),
            ],
            ['is_home' => true, 'is_active' => true],
        );
    }

    private function createControlAccount(Company $company, string $currencyCode, AccountSubtype $subtype): Account
    {
        $home = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('subtype', $subtype->value)
            ->whereNull('currency_code')
            ->where('is_system', true)
            ->orderBy('code')
            ->first();

        $baseCode = $home?->code ?? ($subtype === AccountSubtype::AccountsReceivable ? '1100' : '2000');
        $baseName = $home?->name ?? $subtype->label();

        return $this->makeAccount($company, [
            'code' => $this->uniqueCode($company, $baseCode.'-'.$currencyCode),
            'name' => $baseName.' ('.$currencyCode.')',
            'subtype' => $subtype,
            'currency_code' => $currencyCode,
            'is_system' => true,
            'description' => "Foreign {$subtype->label()} control account for {$currencyCode}.",
        ]);
    }

    private function createSystemAccount(Company $company, AccountSubtype $subtype, string $desiredCode, string $name, string $description): Account
    {
        return $this->makeAccount($company, [
            'code' => $this->uniqueCode($company, $desiredCode),
            'name' => $name,
            'subtype' => $subtype,
            'currency_code' => null,
            'is_system' => true,
            'description' => $description,
        ]);
    }

    /**
     * @param  array{code: string, name: string, subtype: AccountSubtype, currency_code: ?string, is_system: bool, description: string}  $attributes
     */
    private function makeAccount(Company $company, array $attributes): Account
    {
        $subtype = $attributes['subtype'];

        return Account::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => $attributes['code'],
            'name' => $attributes['name'],
            'type' => $subtype->type(),
            'subtype' => $subtype,
            'normal_balance' => $subtype->type()->normalBalance(),
            'currency_code' => $attributes['currency_code'],
            'is_system' => $attributes['is_system'],
            'is_active' => true,
            'description' => $attributes['description'],
        ]);
    }

    /**
     * Return $desired if free, else append -2, -3, … until a free code is found.
     */
    private function uniqueCode(Company $company, string $desired): string
    {
        $code = $desired;
        $suffix = 1;

        while (Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', $code)
            ->exists()
        ) {
            $suffix++;
            $code = $desired.'-'.$suffix;
        }

        return $code;
    }
}
