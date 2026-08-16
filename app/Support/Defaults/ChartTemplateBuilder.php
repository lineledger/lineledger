<?php

namespace App\Support\Defaults;

use App\Enums\AccountSubtype;
use App\Enums\Country;
use App\Enums\Industry;
use App\Enums\OrganizationType;
use App\Models\Account;
use App\Support\Tax\ProvincialSalesTax;
use LogicException;

/**
 * Composes the chart of accounts shown in the setup wizard and seeded on
 * confirmation. The SAME builder feeds both the live preview and the actual
 * seeding, so they can never diverge.
 *
 * A chart is a jurisdiction "core" (system/control accounts + one bank +
 * Opening Balance Equity, with jurisdiction-specific naming) optionally
 * layered with a jurisdiction-neutral, industry-specific operating set. Core
 * accounts are always present and locked (the user cannot deselect them) —
 * except the feature-gated ones (inventory, sales tax, employee reimbursements),
 * which are dropped when the wizard reports the matching capability as unused so
 * a company isn't seeded with system accounts it will never post to (re-enabling
 * the capability later backfills them; see EnsureInventoryAccounts). The
 * industry layer is optional. An org-type pass canonicalizes the equity section
 * to match the entity type: for-profits get owner/partner/share-capital naming,
 * while non-profits relabel the core Retained Earnings line to "Net Assets" and
 * gain the net-asset classes + deferred-contribution liabilities (see applyOrgType).
 *
 * Code bands are reserved so core + any one industry never collide; build()
 * asserts uniqueness so a future editing mistake fails loudly in tests.
 */
class ChartTemplateBuilder
{
    /**
     * Core system accounts seeded only when the matching capability is in use.
     * Each maps an account code to the feature key (passed to build() via
     * $features) that justifies it. When the key is explicitly false the account
     * is omitted from the chart; a missing key defaults to "on" so non-wizard
     * callers (and the accounts() fallback) keep the full core. Re-enabling the
     * capability later backfills the account — EnsureInventoryAccounts for
     * inventory; the tax agency/codes re-seed once a TaxPayable account exists.
     */
    private const FEATURE_GATED_CORE = [
        '1400' => 'inventory',   // Inventory Asset
        '5000' => 'inventory',   // Cost of Goods Sold
        '2200' => 'sales_tax',   // GST/HST (CA) / Sales Tax (US) Payable
        '2300' => 'employees',   // Employee Reimbursements Payable
    ];

    /**
     * The liability account that holds provincial sales tax collected, added for
     * PST/RST provinces when the company reports it charges that tax. Mirrors the
     * 2210 code carried by the full (non-wizard) Canadian chart.
     */
    private const PST_PAYABLE_CODE = '2210';

    /**
     * @param  array<string, bool>  $features  Capability gates (keys: inventory,
     *                                         sales_tax, employees for
     *                                         FEATURE_GATED_CORE; fixed_assets to
     *                                         drop capital-asset accounts; pst to
     *                                         add the provincial sales-tax payable).
     *                                         A missing FEATURE_GATED_CORE/fixed_assets
     *                                         key keeps the account; pst defaults off.
     * @param  string|null  $region  Province/state code (company.address_region),
     *                               used to resolve the provincial sales tax.
     * @return list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool, description?: string, locked: bool, default_selected: bool}>
     */
    public function build(
        Country $jurisdiction,
        Industry $industry,
        OrganizationType $orgType,
        bool $minimal = false,
        array $features = [],
        ?string $region = null,
    ): array {
        $rows = [];

        foreach ($jurisdiction->defaults()->coreAccounts() as $row) {
            $gate = self::FEATURE_GATED_CORE[$row['code']] ?? null;

            if ($gate !== null && ($features[$gate] ?? true) === false) {
                continue;
            }

            $rows[] = $this->normalize($row, locked: true);
        }

        if (! $minimal) {
            foreach ($this->industryAccounts($industry) as $row) {
                $rows[] = $this->normalize($row, locked: false);
            }

            foreach ($this->commonOptionalAccounts() as $row) {
                $rows[] = $this->normalize($row, locked: false);
            }
        }

        // Drop fixed-asset accounts when the company isn't tracking fixed assets,
        // so the chart isn't padded with capital-asset and accumulated-depreciation
        // lines it will never post to. Re-enabling the feature lets the user add
        // them back. Catches the accumulated-depreciation rows too — they share the
        // FixedAsset subtype.
        if (($features['fixed_assets'] ?? true) === false) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => $row['subtype'] !== AccountSubtype::FixedAsset,
            ));
        }

        // Provinces that levy a separate provincial sales tax (BC/SK PST, MB RST)
        // get the matching payable account, locked on like the GST/HST one, so the
        // tax collected can be tracked as a liability. HST and GST-only provinces
        // never see it.
        if (($features['pst'] ?? false) === true
            && ($pst = ProvincialSalesTax::forRegion($region)) !== null) {
            $rows[] = $this->normalize(
                ['code' => self::PST_PAYABLE_CODE, 'name' => $pst->payableAccountName(), 'subtype' => AccountSubtype::TaxPayable],
                locked: true,
            );
        }

        $rows = $this->applyOrgType($rows, $orgType, $jurisdiction);

        usort($rows, fn (array $a, array $b) => strcmp($a['code'], $b['code']));

        $this->assertUniqueCodes($rows);

        return $rows;
    }

    /**
     * Strip preview-only keys, returning rows in the shape the observer seeds
     * from. Keeps only the codes in $selectedCodes.
     *
     * @param  list<array<string, mixed>>  $previewRows
     * @param  list<string>  $selectedCodes
     * @return list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool, description?: string, gifi_code?: string, parent_code?: string}>
     */
    public static function toSeedRows(array $previewRows, array $selectedCodes): array
    {
        $seed = [];
        // Normalise to strings: numeric-string codes can arrive as integers after
        // round-tripping through PHP array keys, which would break strict matching.
        $selected = array_map('strval', $selectedCodes);

        foreach ($previewRows as $row) {
            if (! in_array((string) $row['code'], $selected, true)) {
                continue;
            }

            $seed[] = array_filter(
                [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'subtype' => $row['subtype'],
                    'is_system' => $row['is_system'],
                    'description' => $row['description'] ?? null,
                    'gifi_code' => $row['gifi_code'] ?? null,
                    'parent_code' => $row['parent_code'] ?? null,
                ],
                fn ($value) => $value !== null,
            );
        }

        return $seed;
    }

    /**
     * Build preview rows from an existing company's chart, for the wizard's
     * "copy from an existing organization" mode. Mirrors the normalize() shape so
     * the copied chart flows through the same review → toSeedRows → seed pipeline,
     * and additionally carries gifi_code and the parent link (as parent_code,
     * since account ids differ in the new company but codes are unique per
     * company). System accounts are locked on, exactly like a built core chart.
     *
     * @param  iterable<Account>  $accounts
     * @return list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool, description: string|null, gifi_code: string|null, parent_code: string|null, locked: bool, default_selected: bool}>
     */
    public static function fromCompanyAccounts(iterable $accounts): array
    {
        $codeById = [];
        foreach ($accounts as $a) {
            $codeById[$a->id] = $a->code;
        }

        $rows = [];
        foreach ($accounts as $a) {
            $rows[] = [
                'code' => $a->code,
                'name' => $a->name,
                // Rebuild the enum from its backing value: the model casts subtype
                // to AccountSubtype, but static analysis reads the raw string column.
                'subtype' => AccountSubtype::from((string) $a->getRawOriginal('subtype')),
                'is_system' => (bool) $a->is_system,
                'description' => $a->description,
                'gifi_code' => $a->gifi_code,
                'parent_code' => $a->parent_id !== null ? ($codeById[$a->parent_id] ?? null) : null,
                'locked' => (bool) $a->is_system,
                'default_selected' => true,
            ];
        }

        usort($rows, fn (array $x, array $y) => strcmp((string) $x['code'], (string) $y['code']));

        return $rows;
    }

    /**
     * @param  array{code: string, name: string, subtype: AccountSubtype, is_system?: bool, description?: string}  $row
     * @return array{code: string, name: string, subtype: AccountSubtype, is_system: bool, description?: string, locked: bool, default_selected: bool}
     */
    protected function normalize(array $row, bool $locked): array
    {
        return [
            'code' => $row['code'],
            'name' => $row['name'],
            'subtype' => $row['subtype'],
            'is_system' => $row['is_system'] ?? false,
            ...isset($row['description']) ? ['description' => $row['description']] : [],
            'locked' => $locked,
            'default_selected' => true,
        ];
    }

    /**
     * Adjust the chart to match the organization type. For-profits get their
     * equity section canonicalized to the entity type (see applyForProfitEquity).
     * For non-profits this relabels the core Retained Earnings line to "Net Assets"
     * (its subtype stays RetainedEarnings so the current-period excess still rolls
     * forward) and guarantees the net-asset classes + deferred-contribution
     * liabilities exist with the correct subtypes — regardless of the industry
     * layer chosen, so a charity that picked, say, the Retail industry still gets
     * the NPO scaffolding.
     *
     * @param  list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool, description?: string, locked: bool, default_selected: bool}>  $rows
     * @return list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool, description?: string, locked: bool, default_selected: bool}>
     */
    protected function applyOrgType(array $rows, OrganizationType $orgType, Country $jurisdiction): array
    {
        if (! $orgType->isNonProfit()) {
            return $this->applyForProfitEquity($rows, $orgType, $jurisdiction);
        }

        $rows = array_map(function (array $row) {
            if ($row['code'] === '3900') {
                $row['name'] = 'Net Assets';
            }

            // The opening-balance balancing account reads as "Net Assets" too, so
            // a non-profit's statement of financial position never shows "Equity".
            if ($row['code'] === '3000') {
                $row['name'] = Account::OPENING_BALANCE_NET_ASSETS_NAME;
            }

            return $row;
        }, $rows);

        return $orgType->isClub()
            ? $this->upsertClubAccounts($rows)
            : $this->upsertNonProfitAccounts($rows, $orgType);
    }

    /**
     * Canonicalize the for-profit equity section so it reflects the chosen entity
     * type rather than whatever the industry layer happened to hardcode (e.g. the
     * Manufacturing set names 3200 "Owner Draws / Dividends", wrong for a sole
     * proprietor). Rename-in-place only: we never inject equity accounts here, so a
     * minimal chart — which carries no 3100/3200 — stays limited to Opening Balance
     * Equity + Retained Earnings. Corporation terminology follows the jurisdiction
     * (US "stock", Canada "shares"). "Other" maps to the proprietor model.
     *
     * @param  list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool, description?: string, locked: bool, default_selected: bool}>  $rows
     * @return list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool, description?: string, locked: bool, default_selected: bool}>
     */
    protected function applyForProfitEquity(array $rows, OrganizationType $orgType, Country $jurisdiction): array
    {
        $specs = match ($orgType->equityModel()) {
            'partnership' => [
                '3100' => ['Partner Contributions', AccountSubtype::Equity],
                '3200' => ['Partner Draws', AccountSubtype::Equity],
            ],
            'corporation' => [
                '3100' => [$jurisdiction === Country::Canada ? 'Common Shares' : 'Common Stock', AccountSubtype::Equity],
                '3200' => ['Shareholder Distributions', AccountSubtype::Equity],
            ],
            // 'proprietor' — and 'Other', which maps to it.
            default => [
                '3100' => ['Owner Contributions', AccountSubtype::Equity],
                '3200' => ['Owner Draws', AccountSubtype::Equity],
            ],
        };

        return $this->renameAccounts($rows, $specs);
    }

    /**
     * Update the name + subtype of existing rows by code, in place. Unlike
     * upsertAccounts(), codes that aren't already present are skipped (never
     * injected) — used where a missing account should stay missing, e.g. so a
     * minimal chart isn't padded with optional equity lines.
     *
     * @param  list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool, description?: string, locked: bool, default_selected: bool}>  $rows
     * @param  array<int|string, array{0: string, 1: AccountSubtype}>  $specs
     * @return list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool, description?: string, locked: bool, default_selected: bool}>
     */
    protected function renameAccounts(array $rows, array $specs): array
    {
        foreach ($rows as $i => $row) {
            if (isset($specs[$row['code']])) {
                [$name, $subtype] = $specs[$row['code']];
                $rows[$i]['name'] = $name;
                $rows[$i]['subtype'] = $subtype;
            }
        }

        return $rows;
    }

    /**
     * The lighter, member-dues-focused set for an unincorporated club/association:
     * membership-dues income, a deferred-dues liability for dues paid in advance,
     * and a single unrestricted net-asset line. No grants, restricted funds, or
     * endowment — a club doesn't need them.
     *
     * @param  list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool, description?: string, locked: bool, default_selected: bool}>  $rows
     * @return list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool, description?: string, locked: bool, default_selected: bool}>
     */
    protected function upsertClubAccounts(array $rows): array
    {
        return $this->upsertAccounts($rows, [
            '2510' => ['Deferred Membership Dues', AccountSubtype::CurrentLiability],
            '3100' => ['Unrestricted Net Assets', AccountSubtype::UnrestrictedNetAssets],
            '4200' => ['Membership Dues', AccountSubtype::Income],
        ]);
    }

    /**
     * Net-asset classes and deferred-contribution liabilities every non-profit
     * needs. Endowment net assets are seeded only for registered charities.
     *
     * @param  list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool, description?: string, locked: bool, default_selected: bool}>  $rows
     * @return list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool, description?: string, locked: bool, default_selected: bool}>
     */
    protected function upsertNonProfitAccounts(array $rows, OrganizationType $orgType): array
    {
        $specs = [
            '2500' => ['Deferred / Restricted Grants', AccountSubtype::CurrentLiability],
            '2510' => ['Deferred Membership / Program Revenue', AccountSubtype::CurrentLiability],
            '3100' => ['Unrestricted Net Assets', AccountSubtype::UnrestrictedNetAssets],
            '3200' => ['Restricted Net Assets', AccountSubtype::RestrictedNetAssets],
        ];

        if ($orgType === OrganizationType::Charity) {
            $specs['3300'] = ['Endowment Net Assets', AccountSubtype::EndowmentNetAssets];
        }

        return $this->upsertAccounts($rows, $specs);
    }

    /**
     * Upsert a set of accounts by code: a row that already exists is upgraded in
     * place to the given name + subtype; a missing one is injected. Upserting
     * (rather than blindly adding) keeps assertUniqueCodes() happy.
     *
     * @param  list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool, description?: string, locked: bool, default_selected: bool}>  $rows
     * @param  array<string, array{0: string, 1: AccountSubtype}>  $specs
     * @return list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool, description?: string, locked: bool, default_selected: bool}>
     */
    protected function upsertAccounts(array $rows, array $specs): array
    {
        $indexByCode = [];
        foreach ($rows as $i => $row) {
            $indexByCode[$row['code']] = $i;
        }

        foreach ($specs as $code => [$name, $subtype]) {
            if (isset($indexByCode[$code])) {
                $rows[$indexByCode[$code]]['name'] = $name;
                $rows[$indexByCode[$code]]['subtype'] = $subtype;

                continue;
            }

            $rows[] = $this->normalize(
                ['code' => $code, 'name' => $name, 'subtype' => $subtype],
                locked: false,
            );
        }

        return $rows;
    }

    /**
     * Jurisdiction- and industry-neutral optional accounts layered onto every
     * full (non-minimal) chart. Codes must stay clear of the core and of every
     * industry set — assertUniqueCodes() in build() fails loudly if they
     * collide.
     *
     * @return list<array{code: string, name: string, subtype: AccountSubtype, description: string}>
     */
    protected function commonOptionalAccounts(): array
    {
        return [
            ['code' => '2700', 'name' => 'Bank Loan', 'subtype' => AccountSubtype::LongTermLiability, 'description' => 'Principal owing on bank loans. For loan payments, split principal to this account and interest to an interest expense account.'],
        ];
    }

    /**
     * @param  list<array{code: string}>  $rows
     */
    protected function assertUniqueCodes(array $rows): void
    {
        $codes = array_column($rows, 'code');
        $duplicates = array_keys(array_filter(array_count_values($codes), fn (int $n) => $n > 1));

        if ($duplicates !== []) {
            throw new LogicException('Duplicate account codes in chart template: '.implode(', ', $duplicates));
        }
    }

    /**
     * Jurisdiction-neutral operating accounts layered on top of the core. Codes
     * stay within the reserved bands (assets 15xx–18xx + extra inventory
     * 141x–143x, liabilities 24xx–28xx, equity 31xx–38xx, income 40xx–48xx,
     * COGS detail 51xx–58xx, expense 60xx–68xx) so they never collide with the
     * fixed core codes.
     *
     * @return list<array{code: string, name: string, subtype: AccountSubtype}>
     */
    protected function industryAccounts(Industry $industry): array
    {
        return match ($industry) {
            Industry::General => [
                ['code' => '1010', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank],
                ['code' => '1300', 'name' => 'Prepaid Expenses', 'subtype' => AccountSubtype::CurrentAsset],
                ['code' => '1500', 'name' => 'Office Equipment', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '1510', 'name' => 'Accumulated Depreciation', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '2100', 'name' => 'Credit Card', 'subtype' => AccountSubtype::CreditCard],
                ['code' => '3100', 'name' => 'Owner Contributions', 'subtype' => AccountSubtype::Equity],
                ['code' => '3200', 'name' => 'Owner Draws', 'subtype' => AccountSubtype::Equity],
                ['code' => '4000', 'name' => 'Sales', 'subtype' => AccountSubtype::Income],
                ['code' => '4100', 'name' => 'Services', 'subtype' => AccountSubtype::Income],
                ['code' => '4900', 'name' => 'Other Income', 'subtype' => AccountSubtype::OtherIncome],
                ['code' => '6000', 'name' => 'Advertising', 'subtype' => AccountSubtype::Expense],
                ['code' => '6010', 'name' => 'Bank Charges', 'subtype' => AccountSubtype::Expense],
                ['code' => '6020', 'name' => 'Insurance', 'subtype' => AccountSubtype::Expense],
                ['code' => '6030', 'name' => 'Meals & Entertainment', 'subtype' => AccountSubtype::Expense],
                ['code' => '6040', 'name' => 'Office Supplies', 'subtype' => AccountSubtype::Expense],
                ['code' => '6050', 'name' => 'Professional Fees', 'subtype' => AccountSubtype::Expense],
                ['code' => '6060', 'name' => 'Rent', 'subtype' => AccountSubtype::Expense],
                ['code' => '6070', 'name' => 'Software & Subscriptions', 'subtype' => AccountSubtype::Expense],
                ['code' => '6080', 'name' => 'Telephone & Internet', 'subtype' => AccountSubtype::Expense],
                ['code' => '6090', 'name' => 'Travel', 'subtype' => AccountSubtype::Expense],
                ['code' => '6100', 'name' => 'Utilities', 'subtype' => AccountSubtype::Expense],
                ['code' => '6900', 'name' => 'Miscellaneous Expense', 'subtype' => AccountSubtype::OtherExpense],
            ],
            Industry::Contractor => [
                ['code' => '1300', 'name' => 'Prepaid Expenses', 'subtype' => AccountSubtype::CurrentAsset],
                ['code' => '1600', 'name' => 'Construction Equipment', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '1610', 'name' => 'Accumulated Depreciation — Equipment', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '1700', 'name' => 'Vehicles', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '1710', 'name' => 'Accumulated Depreciation — Vehicles', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '2100', 'name' => 'Credit Card', 'subtype' => AccountSubtype::CreditCard],
                ['code' => '2400', 'name' => 'Customer Deposits & Retainage Held', 'subtype' => AccountSubtype::CurrentLiability],
                ['code' => '2410', 'name' => 'Subcontractor Holdbacks Payable', 'subtype' => AccountSubtype::CurrentLiability],
                ['code' => '3100', 'name' => 'Owner Contributions', 'subtype' => AccountSubtype::Equity],
                ['code' => '3200', 'name' => 'Owner Draws', 'subtype' => AccountSubtype::Equity],
                ['code' => '4000', 'name' => 'Construction Revenue', 'subtype' => AccountSubtype::Income],
                ['code' => '4100', 'name' => 'Service & Repair Income', 'subtype' => AccountSubtype::Income],
                ['code' => '4200', 'name' => 'Change Order Income', 'subtype' => AccountSubtype::Income],
                ['code' => '4900', 'name' => 'Other Income', 'subtype' => AccountSubtype::OtherIncome],
                ['code' => '5100', 'name' => 'Direct Materials', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '5200', 'name' => 'Subcontractor Costs', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '5300', 'name' => 'Direct Labour', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '5400', 'name' => 'Equipment Rental', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '5500', 'name' => 'Permits & Inspection Fees', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '6000', 'name' => 'Advertising', 'subtype' => AccountSubtype::Expense],
                ['code' => '6010', 'name' => 'Bank Charges', 'subtype' => AccountSubtype::Expense],
                ['code' => '6020', 'name' => 'Insurance (Liability & Bonding)', 'subtype' => AccountSubtype::Expense],
                ['code' => '6060', 'name' => 'Rent', 'subtype' => AccountSubtype::Expense],
                ['code' => '6200', 'name' => 'Tools & Small Equipment', 'subtype' => AccountSubtype::Expense],
                ['code' => '6210', 'name' => 'Vehicle & Fuel', 'subtype' => AccountSubtype::Expense],
                ['code' => '6220', 'name' => 'Job Site Utilities', 'subtype' => AccountSubtype::Expense],
                ['code' => '6900', 'name' => 'Miscellaneous Expense', 'subtype' => AccountSubtype::OtherExpense],
            ],
            Industry::NonProfit => [
                ['code' => '1010', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank],
                ['code' => '1300', 'name' => 'Prepaid Expenses', 'subtype' => AccountSubtype::CurrentAsset],
                ['code' => '1500', 'name' => 'Office Equipment', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '1510', 'name' => 'Accumulated Depreciation', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '2100', 'name' => 'Credit Card', 'subtype' => AccountSubtype::CreditCard],
                ['code' => '2500', 'name' => 'Deferred / Restricted Grants', 'subtype' => AccountSubtype::CurrentLiability],
                ['code' => '3100', 'name' => 'Unrestricted Net Assets', 'subtype' => AccountSubtype::Equity],
                ['code' => '3200', 'name' => 'Restricted Net Assets', 'subtype' => AccountSubtype::Equity],
                ['code' => '4000', 'name' => 'Donations & Contributions', 'subtype' => AccountSubtype::Income],
                ['code' => '4100', 'name' => 'Grant Revenue', 'subtype' => AccountSubtype::Income],
                ['code' => '4200', 'name' => 'Membership Dues', 'subtype' => AccountSubtype::Income],
                ['code' => '4300', 'name' => 'Fundraising Event Income', 'subtype' => AccountSubtype::Income],
                ['code' => '4400', 'name' => 'Program Service Fees', 'subtype' => AccountSubtype::Income],
                ['code' => '4900', 'name' => 'Investment & Other Income', 'subtype' => AccountSubtype::OtherIncome],
                ['code' => '6000', 'name' => 'Advertising & Outreach', 'subtype' => AccountSubtype::Expense],
                ['code' => '6020', 'name' => 'Insurance', 'subtype' => AccountSubtype::Expense],
                ['code' => '6050', 'name' => 'Professional Fees', 'subtype' => AccountSubtype::Expense],
                ['code' => '6060', 'name' => 'Rent', 'subtype' => AccountSubtype::Expense],
                ['code' => '6300', 'name' => 'Program Expenses', 'subtype' => AccountSubtype::Expense],
                ['code' => '6310', 'name' => 'Fundraising Expenses', 'subtype' => AccountSubtype::Expense],
                ['code' => '6320', 'name' => 'Grants & Assistance Paid', 'subtype' => AccountSubtype::Expense],
                ['code' => '6900', 'name' => 'Miscellaneous Expense', 'subtype' => AccountSubtype::OtherExpense],
            ],
            Industry::Manufacturing => [
                ['code' => '1300', 'name' => 'Prepaid Expenses', 'subtype' => AccountSubtype::CurrentAsset],
                ['code' => '1410', 'name' => 'Raw Materials Inventory', 'subtype' => AccountSubtype::Inventory],
                ['code' => '1420', 'name' => 'Work in Process Inventory', 'subtype' => AccountSubtype::Inventory],
                ['code' => '1430', 'name' => 'Finished Goods Inventory', 'subtype' => AccountSubtype::Inventory],
                ['code' => '1600', 'name' => 'Machinery & Equipment', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '1610', 'name' => 'Accumulated Depreciation — Machinery', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '2100', 'name' => 'Credit Card', 'subtype' => AccountSubtype::CreditCard],
                ['code' => '3100', 'name' => 'Owner / Shareholder Contributions', 'subtype' => AccountSubtype::Equity],
                ['code' => '3200', 'name' => 'Owner Draws / Dividends', 'subtype' => AccountSubtype::Equity],
                ['code' => '4000', 'name' => 'Product Sales', 'subtype' => AccountSubtype::Income],
                ['code' => '4100', 'name' => 'Contract Manufacturing Income', 'subtype' => AccountSubtype::Income],
                ['code' => '4900', 'name' => 'Other Income', 'subtype' => AccountSubtype::OtherIncome],
                ['code' => '5100', 'name' => 'Direct Materials Used', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '5200', 'name' => 'Direct Labour', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '5300', 'name' => 'Manufacturing Overhead Applied', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '5400', 'name' => 'Freight In', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '5500', 'name' => 'Inventory Adjustments', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '6000', 'name' => 'Advertising', 'subtype' => AccountSubtype::Expense],
                ['code' => '6020', 'name' => 'Insurance', 'subtype' => AccountSubtype::Expense],
                ['code' => '6060', 'name' => 'Rent — Factory & Office', 'subtype' => AccountSubtype::Expense],
                ['code' => '6100', 'name' => 'Utilities', 'subtype' => AccountSubtype::Expense],
                ['code' => '6400', 'name' => 'Repairs & Maintenance', 'subtype' => AccountSubtype::Expense],
                ['code' => '6410', 'name' => 'Shipping & Distribution', 'subtype' => AccountSubtype::Expense],
                ['code' => '6900', 'name' => 'Miscellaneous Expense', 'subtype' => AccountSubtype::OtherExpense],
            ],
            Industry::Retail => [
                ['code' => '1010', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank],
                ['code' => '1300', 'name' => 'Prepaid Expenses', 'subtype' => AccountSubtype::CurrentAsset],
                ['code' => '1500', 'name' => 'Store Fixtures & Equipment', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '1510', 'name' => 'Accumulated Depreciation', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '2100', 'name' => 'Credit Card', 'subtype' => AccountSubtype::CreditCard],
                ['code' => '2400', 'name' => 'Gift Cards Outstanding', 'subtype' => AccountSubtype::CurrentLiability],
                ['code' => '2410', 'name' => 'Customer Deposits', 'subtype' => AccountSubtype::CurrentLiability],
                ['code' => '3100', 'name' => 'Owner Contributions', 'subtype' => AccountSubtype::Equity],
                ['code' => '3200', 'name' => 'Owner Draws', 'subtype' => AccountSubtype::Equity],
                ['code' => '4000', 'name' => 'Merchandise Sales', 'subtype' => AccountSubtype::Income],
                ['code' => '4100', 'name' => 'Online Sales', 'subtype' => AccountSubtype::Income],
                ['code' => '4200', 'name' => 'Sales Discounts', 'subtype' => AccountSubtype::Income],
                ['code' => '4900', 'name' => 'Other Income', 'subtype' => AccountSubtype::OtherIncome],
                ['code' => '5100', 'name' => 'Cost of Merchandise Sold', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '5200', 'name' => 'Freight In', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '5300', 'name' => 'Inventory Shrinkage', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '6000', 'name' => 'Advertising & Promotion', 'subtype' => AccountSubtype::Expense],
                ['code' => '6010', 'name' => 'Merchant / Card Processing Fees', 'subtype' => AccountSubtype::Expense],
                ['code' => '6020', 'name' => 'Insurance', 'subtype' => AccountSubtype::Expense],
                ['code' => '6060', 'name' => 'Rent', 'subtype' => AccountSubtype::Expense],
                ['code' => '6100', 'name' => 'Utilities', 'subtype' => AccountSubtype::Expense],
                ['code' => '6500', 'name' => 'Packaging & Supplies', 'subtype' => AccountSubtype::Expense],
                ['code' => '6900', 'name' => 'Miscellaneous Expense', 'subtype' => AccountSubtype::OtherExpense],
            ],
            Industry::ProfessionalServices => [
                ['code' => '1010', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank],
                ['code' => '1300', 'name' => 'Prepaid Expenses', 'subtype' => AccountSubtype::CurrentAsset],
                ['code' => '1500', 'name' => 'Office Equipment', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '1510', 'name' => 'Accumulated Depreciation', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '2100', 'name' => 'Credit Card', 'subtype' => AccountSubtype::CreditCard],
                ['code' => '2400', 'name' => 'Unearned / Retainer Revenue', 'subtype' => AccountSubtype::CurrentLiability],
                ['code' => '3100', 'name' => 'Owner / Partner Contributions', 'subtype' => AccountSubtype::Equity],
                ['code' => '3200', 'name' => 'Owner / Partner Draws', 'subtype' => AccountSubtype::Equity],
                ['code' => '4000', 'name' => 'Consulting & Service Fees', 'subtype' => AccountSubtype::Income],
                ['code' => '4100', 'name' => 'Reimbursable Expense Income', 'subtype' => AccountSubtype::Income],
                ['code' => '4900', 'name' => 'Other Income', 'subtype' => AccountSubtype::OtherIncome],
                ['code' => '5100', 'name' => 'Subcontractor & Direct Project Costs', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '6000', 'name' => 'Advertising & Marketing', 'subtype' => AccountSubtype::Expense],
                ['code' => '6010', 'name' => 'Bank Charges', 'subtype' => AccountSubtype::Expense],
                ['code' => '6020', 'name' => 'Insurance (E&O / Liability)', 'subtype' => AccountSubtype::Expense],
                ['code' => '6040', 'name' => 'Office Supplies', 'subtype' => AccountSubtype::Expense],
                ['code' => '6050', 'name' => 'Professional Development & Dues', 'subtype' => AccountSubtype::Expense],
                ['code' => '6060', 'name' => 'Rent', 'subtype' => AccountSubtype::Expense],
                ['code' => '6070', 'name' => 'Software & Subscriptions', 'subtype' => AccountSubtype::Expense],
                ['code' => '6080', 'name' => 'Telephone & Internet', 'subtype' => AccountSubtype::Expense],
                ['code' => '6090', 'name' => 'Travel', 'subtype' => AccountSubtype::Expense],
                ['code' => '6900', 'name' => 'Miscellaneous Expense', 'subtype' => AccountSubtype::OtherExpense],
            ],
            Industry::HealthWellness => [
                ['code' => '1010', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank],
                ['code' => '1300', 'name' => 'Prepaid Expenses', 'subtype' => AccountSubtype::CurrentAsset],
                ['code' => '1410', 'name' => 'Retail Product Inventory', 'subtype' => AccountSubtype::Inventory],
                ['code' => '1500', 'name' => 'Equipment & Fixtures', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '1510', 'name' => 'Accumulated Depreciation', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '2100', 'name' => 'Credit Card', 'subtype' => AccountSubtype::CreditCard],
                ['code' => '2400', 'name' => 'Prepaid Packages & Sessions', 'subtype' => AccountSubtype::CurrentLiability],
                ['code' => '2410', 'name' => 'Gift Cards Outstanding', 'subtype' => AccountSubtype::CurrentLiability],
                ['code' => '2420', 'name' => 'Tips Payable', 'subtype' => AccountSubtype::CurrentLiability],
                ['code' => '3100', 'name' => 'Owner Contributions', 'subtype' => AccountSubtype::Equity],
                ['code' => '3200', 'name' => 'Owner Draws', 'subtype' => AccountSubtype::Equity],
                ['code' => '4000', 'name' => 'Service & Treatment Fees', 'subtype' => AccountSubtype::Income],
                ['code' => '4100', 'name' => 'Class & Package Income', 'subtype' => AccountSubtype::Income],
                ['code' => '4200', 'name' => 'Retail Product Sales', 'subtype' => AccountSubtype::Income],
                ['code' => '4900', 'name' => 'Other Income', 'subtype' => AccountSubtype::OtherIncome],
                ['code' => '5100', 'name' => 'Cost of Products Sold', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '6000', 'name' => 'Advertising & Marketing', 'subtype' => AccountSubtype::Expense],
                ['code' => '6010', 'name' => 'Merchant / Card Processing Fees', 'subtype' => AccountSubtype::Expense],
                ['code' => '6020', 'name' => 'Insurance (Professional Liability)', 'subtype' => AccountSubtype::Expense],
                ['code' => '6050', 'name' => 'Licensing & Continuing Education', 'subtype' => AccountSubtype::Expense],
                ['code' => '6060', 'name' => 'Rent', 'subtype' => AccountSubtype::Expense],
                ['code' => '6100', 'name' => 'Utilities', 'subtype' => AccountSubtype::Expense],
                ['code' => '6500', 'name' => 'Supplies & Consumables', 'subtype' => AccountSubtype::Expense],
                ['code' => '6900', 'name' => 'Miscellaneous Expense', 'subtype' => AccountSubtype::OtherExpense],
            ],
            Industry::Restaurant => [
                ['code' => '1010', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank],
                ['code' => '1300', 'name' => 'Prepaid Expenses', 'subtype' => AccountSubtype::CurrentAsset],
                ['code' => '1410', 'name' => 'Food Inventory', 'subtype' => AccountSubtype::Inventory],
                ['code' => '1420', 'name' => 'Beverage Inventory', 'subtype' => AccountSubtype::Inventory],
                ['code' => '1500', 'name' => 'Kitchen Equipment & Fixtures', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '1510', 'name' => 'Accumulated Depreciation', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '2100', 'name' => 'Credit Card', 'subtype' => AccountSubtype::CreditCard],
                ['code' => '2400', 'name' => 'Gift Cards Outstanding', 'subtype' => AccountSubtype::CurrentLiability],
                ['code' => '2420', 'name' => 'Tips Payable', 'subtype' => AccountSubtype::CurrentLiability],
                ['code' => '3100', 'name' => 'Owner Contributions', 'subtype' => AccountSubtype::Equity],
                ['code' => '3200', 'name' => 'Owner Draws', 'subtype' => AccountSubtype::Equity],
                ['code' => '4000', 'name' => 'Food Sales', 'subtype' => AccountSubtype::Income],
                ['code' => '4100', 'name' => 'Beverage Sales', 'subtype' => AccountSubtype::Income],
                ['code' => '4200', 'name' => 'Catering & Events Income', 'subtype' => AccountSubtype::Income],
                ['code' => '4900', 'name' => 'Other Income', 'subtype' => AccountSubtype::OtherIncome],
                ['code' => '5100', 'name' => 'Cost of Food Sold', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '5200', 'name' => 'Cost of Beverage Sold', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '6000', 'name' => 'Advertising & Promotion', 'subtype' => AccountSubtype::Expense],
                ['code' => '6010', 'name' => 'Merchant / Card Processing Fees', 'subtype' => AccountSubtype::Expense],
                ['code' => '6020', 'name' => 'Insurance', 'subtype' => AccountSubtype::Expense],
                ['code' => '6060', 'name' => 'Rent', 'subtype' => AccountSubtype::Expense],
                ['code' => '6100', 'name' => 'Utilities', 'subtype' => AccountSubtype::Expense],
                ['code' => '6500', 'name' => 'Kitchen Supplies & Smallwares', 'subtype' => AccountSubtype::Expense],
                ['code' => '6510', 'name' => 'Cleaning & Sanitation', 'subtype' => AccountSubtype::Expense],
                ['code' => '6900', 'name' => 'Miscellaneous Expense', 'subtype' => AccountSubtype::OtherExpense],
            ],
            Industry::RealEstate => [
                ['code' => '1010', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank],
                ['code' => '1300', 'name' => 'Prepaid Expenses', 'subtype' => AccountSubtype::CurrentAsset],
                ['code' => '1500', 'name' => 'Office Equipment', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '1510', 'name' => 'Accumulated Depreciation', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '1800', 'name' => 'Trust / Escrow Bank Account', 'subtype' => AccountSubtype::Bank],
                ['code' => '2100', 'name' => 'Credit Card', 'subtype' => AccountSubtype::CreditCard],
                ['code' => '2400', 'name' => 'Security Deposits Held', 'subtype' => AccountSubtype::CurrentLiability],
                ['code' => '2410', 'name' => 'Owner / Tenant Funds Held in Trust', 'subtype' => AccountSubtype::CurrentLiability],
                ['code' => '3100', 'name' => 'Owner / Partner Contributions', 'subtype' => AccountSubtype::Equity],
                ['code' => '3200', 'name' => 'Owner / Partner Draws', 'subtype' => AccountSubtype::Equity],
                ['code' => '4000', 'name' => 'Commission Income', 'subtype' => AccountSubtype::Income],
                ['code' => '4100', 'name' => 'Rental Income', 'subtype' => AccountSubtype::Income],
                ['code' => '4200', 'name' => 'Property Management Fees', 'subtype' => AccountSubtype::Income],
                ['code' => '4900', 'name' => 'Other Income', 'subtype' => AccountSubtype::OtherIncome],
                ['code' => '5100', 'name' => 'Agent Commission Splits', 'subtype' => AccountSubtype::CostOfGoodsSold],
                ['code' => '6000', 'name' => 'Advertising & Listings', 'subtype' => AccountSubtype::Expense],
                ['code' => '6010', 'name' => 'Bank Charges', 'subtype' => AccountSubtype::Expense],
                ['code' => '6020', 'name' => 'Insurance', 'subtype' => AccountSubtype::Expense],
                ['code' => '6050', 'name' => 'Licensing & Dues', 'subtype' => AccountSubtype::Expense],
                ['code' => '6060', 'name' => 'Rent — Office', 'subtype' => AccountSubtype::Expense],
                ['code' => '6210', 'name' => 'Vehicle & Travel', 'subtype' => AccountSubtype::Expense],
                ['code' => '6400', 'name' => 'Repairs & Maintenance', 'subtype' => AccountSubtype::Expense],
                ['code' => '6900', 'name' => 'Miscellaneous Expense', 'subtype' => AccountSubtype::OtherExpense],
            ],
            Industry::Freelancer => [
                ['code' => '1010', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank],
                ['code' => '1300', 'name' => 'Prepaid Expenses', 'subtype' => AccountSubtype::CurrentAsset],
                ['code' => '1500', 'name' => 'Equipment & Gear', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '1510', 'name' => 'Accumulated Depreciation', 'subtype' => AccountSubtype::FixedAsset],
                ['code' => '2100', 'name' => 'Credit Card', 'subtype' => AccountSubtype::CreditCard],
                ['code' => '2400', 'name' => 'Client Deposits / Unearned Income', 'subtype' => AccountSubtype::CurrentLiability],
                ['code' => '3100', 'name' => 'Owner Contributions', 'subtype' => AccountSubtype::Equity],
                ['code' => '3200', 'name' => 'Owner Draws', 'subtype' => AccountSubtype::Equity],
                ['code' => '4000', 'name' => 'Service Income', 'subtype' => AccountSubtype::Income],
                ['code' => '4100', 'name' => 'Project & Licensing Income', 'subtype' => AccountSubtype::Income],
                ['code' => '4900', 'name' => 'Other Income', 'subtype' => AccountSubtype::OtherIncome],
                ['code' => '6000', 'name' => 'Advertising & Marketing', 'subtype' => AccountSubtype::Expense],
                ['code' => '6010', 'name' => 'Bank & Payment Fees', 'subtype' => AccountSubtype::Expense],
                ['code' => '6040', 'name' => 'Office Supplies', 'subtype' => AccountSubtype::Expense],
                ['code' => '6050', 'name' => 'Professional Development & Dues', 'subtype' => AccountSubtype::Expense],
                ['code' => '6060', 'name' => 'Rent / Coworking', 'subtype' => AccountSubtype::Expense],
                ['code' => '6070', 'name' => 'Software & Subscriptions', 'subtype' => AccountSubtype::Expense],
                ['code' => '6080', 'name' => 'Telephone & Internet', 'subtype' => AccountSubtype::Expense],
                ['code' => '6090', 'name' => 'Travel', 'subtype' => AccountSubtype::Expense],
                ['code' => '6200', 'name' => 'Contract Labour & Outsourcing', 'subtype' => AccountSubtype::Expense],
                ['code' => '6900', 'name' => 'Miscellaneous Expense', 'subtype' => AccountSubtype::OtherExpense],
            ],
        };
    }
}
