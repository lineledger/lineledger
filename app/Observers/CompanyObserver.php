<?php

namespace App\Observers;

use App\Enums\AccountSubtype;
use App\Enums\Country;
use App\Enums\TaxAppliesTo;
use App\Models\Account;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\TaxAgency;
use App\Models\TaxCode;
use App\Support\Defaults\CompanyDefaults;
use App\Support\Tax\ProvincialSalesTax;

class CompanyObserver
{
    public function created(Company $company): void
    {
        // Seed under the new company's context. The BelongsToCompany hook is
        // authoritative — it forces company_id to the bound `current_company`.
        // When an *additional* company is created while another is bound (the
        // "create company" modal), we must rebind to the new company so its
        // chart of accounts isn't mis-assigned to the previously-bound one.
        $previous = app()->bound('current_company') ? app('current_company') : null;
        app()->instance('current_company', $company);

        try {
            $defaults = $company->jurisdiction->defaults();

            $this->seedChartOfAccounts($company, $defaults);
            $this->seedPaymentTerms($company);
            $this->seedPaymentMethods($company, $defaults);
            $this->seedTaxAgencyAndCodes($company, $defaults);
            $this->setInventoryDefaults($company);
        } finally {
            if ($previous !== null) {
                app()->instance('current_company', $previous);
            } else {
                app()->forgetInstance('current_company');
            }
        }
    }

    protected function setInventoryDefaults(Company $company): void
    {
        $inventoryAsset = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('subtype', AccountSubtype::Inventory->value)
            ->where('is_system', true)
            ->first();

        $cogs = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('subtype', AccountSubtype::CostOfGoodsSold->value)
            ->where('is_system', true)
            ->first();

        if ($inventoryAsset || $cogs) {
            $company->forceFill([
                'default_inventory_asset_account_id' => $inventoryAsset?->id,
                'default_cogs_account_id' => $cogs?->id,
            ])->saveQuietly();
        }
    }

    protected function seedChartOfAccounts(Company $company, CompanyDefaults $defaults): void
    {
        $rows = $company->pendingChartAccounts ?? $defaults->accounts();

        $idByCode = [];
        $pendingParents = [];   // childCode => parentCode

        foreach ($rows as $row) {
            $subtype = $row['subtype'];

            $account = Account::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'code' => $row['code'],
                'name' => $row['name'],
                'type' => $subtype->type(),
                'subtype' => $subtype,
                'normal_balance' => $subtype->type()->normalBalance(),
                'is_system' => $row['is_system'] ?? false,
                'is_active' => true,
                'description' => $row['description'] ?? null,
                'gifi_code' => $row['gifi_code'] ?? null,
            ]);

            $idByCode[$row['code']] = $account->id;

            if (! empty($row['parent_code'])) {
                $pendingParents[$row['code']] = $row['parent_code'];
            }
        }

        // Second pass: resolve parent_code → parent_id now that every account
        // exists. A parent trimmed in the review step leaves the child as a valid
        // top-level account (parent_id stays null).
        foreach ($pendingParents as $code => $parentCode) {
            if (isset($idByCode[$parentCode]) && $idByCode[$parentCode] !== $idByCode[$code]) {
                Account::withoutGlobalScopes()
                    ->whereKey($idByCode[$code])
                    ->update(['parent_id' => $idByCode[$parentCode]]);
            }
        }
    }

    protected function seedPaymentTerms(Company $company): void
    {
        foreach ([
            ['name' => 'Due on receipt', 'days' => 0],
            ['name' => 'Net 15', 'days' => 15],
            ['name' => 'Net 30', 'days' => 30],
            ['name' => 'Net 60', 'days' => 60],
        ] as $row) {
            PaymentTerm::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'name' => $row['name'],
                'days' => $row['days'],
                'is_active' => true,
            ]);
        }
    }

    protected function seedPaymentMethods(Company $company, CompanyDefaults $defaults): void
    {
        foreach ($defaults->paymentMethods() as $row) {
            PaymentMethod::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'name' => $row['name'],
                'is_cheque' => $row['is_cheque'],
                'is_active' => true,
            ]);
        }
    }

    protected function seedTaxAgencyAndCodes(Company $company, CompanyDefaults $defaults): void
    {
        $this->seedDefaultTaxAgencyAndCodes($company, $defaults);
        $this->seedProvincialSalesTax($company);
    }

    /**
     * Seed the jurisdiction's primary tax authority (CRA in Canada, the state
     * department of revenue in the US) and its codes against the system
     * tax-payable account (GST/HST Payable / Sales Tax Payable, code 2200).
     *
     * Keying off the *system* account — rather than the first tax-payable by code
     * — keeps the federal codes off a province-only company's PST Payable account.
     */
    protected function seedDefaultTaxAgencyAndCodes(Company $company, CompanyDefaults $defaults): void
    {
        $agencies = $defaults->taxAgencies();

        if ($agencies === []) {
            return;
        }

        $taxPayable = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('subtype', AccountSubtype::TaxPayable->value)
            ->where('is_system', true)
            ->orderBy('code')
            ->first();

        if (! $taxPayable) {
            return;
        }

        $defaultAgency = null;

        foreach ($agencies as $row) {
            $agency = TaxAgency::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'name' => $row['name'],
                'payable_account_id' => $taxPayable->id,
                'is_active' => true,
            ]);

            $defaultAgency ??= $agency;
        }

        foreach ($defaults->taxCodes() as $row) {
            TaxCode::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'code' => $row['code'],
                'name' => $row['name'],
                'rate_basis_points' => $row['rate_basis_points'],
                'agency_id' => $defaultAgency->id,
                'is_recoverable' => $row['recoverable'],
                'applies_to' => TaxAppliesTo::Both,
                'is_active' => true,
            ]);
        }
    }

    /**
     * Seed the provincial sales tax (BC/SK PST, MB RST, QC QST) for a Canadian
     * company in a province that levies it — a provincial tax agency pointed at the
     * PST Payable account (2210) plus its tax code. Gated on that account existing,
     * so a company that opted out (no 2210 in its chart) is left untouched,
     * mirroring how the federal codes are gated on the GST/HST account.
     * Recoverability follows the tax: PST/RST are not input credits; QST is.
     */
    protected function seedProvincialSalesTax(Company $company): void
    {
        if ($company->jurisdiction !== Country::Canada) {
            return;
        }

        $pst = ProvincialSalesTax::forRegion($company->address_region);

        if ($pst === null) {
            return;
        }

        $pstPayable = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('subtype', AccountSubtype::TaxPayable->value)
            ->where('is_system', false)
            ->where('code', '2210')
            ->first();

        if (! $pstPayable) {
            return;
        }

        $agency = TaxAgency::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => $pst->agencyName(),
            'payable_account_id' => $pstPayable->id,
            'is_active' => true,
        ]);

        TaxCode::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => $pst->taxCode(),
            'name' => $pst->taxCodeName(),
            'rate_basis_points' => $pst->rateBasisPoints(),
            'agency_id' => $agency->id,
            'is_recoverable' => $pst->isRecoverable(),
            'applies_to' => TaxAppliesTo::Both,
            'is_active' => true,
        ]);
    }
}
