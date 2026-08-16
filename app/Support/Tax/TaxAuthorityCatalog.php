<?php

namespace App\Support\Tax;

use App\Enums\Country;
use App\Models\Company;

/**
 * A curated list of well-known tax authorities a company might collect for,
 * used to populate the "New agency" picker on the Tax codes page so the common
 * authorities are a click away instead of typed from scratch. It is purely a
 * convenience catalog: the user can always enter a custom authority, and each
 * entry only suggests a name and a backing payable-account name — nothing is
 * created until the agency is saved.
 *
 * Canada lists the Canada Revenue Agency plus the provinces that levy their own
 * sales tax ({@see ProvincialSalesTax}); the United States lists each state's
 * department of revenue. Entries the company already has an agency for are
 * filtered out by the caller, so a company never sees a duplicate it can't use.
 */
final class TaxAuthorityCatalog
{
    /**
     * Known authorities for the company's jurisdiction, in display order. Each
     * entry carries a stable {@code key} (used only to look the entry back up
     * when the user picks it), the authority {@code name}, and the suggested
     * {@code account_name} for the Tax Payable account that backs it.
     *
     * @return list<array{key: string, name: string, account_name: string}>
     */
    public static function forCompany(Company $company): array
    {
        return match ($company->jurisdiction) {
            Country::Canada => self::canada(),
            Country::UnitedStates => self::unitedStates(),
        };
    }

    /**
     * @return list<array{key: string, name: string, account_name: string}>
     */
    private static function canada(): array
    {
        $entries = [[
            'key' => 'CRA',
            'name' => 'Canada Revenue Agency',
            'account_name' => 'GST/HST Payable',
        ]];

        foreach (ProvincialSalesTax::cases() as $pst) {
            $entries[] = [
                'key' => $pst->taxCode(),
                'name' => $pst->agencyName(),
                'account_name' => $pst->payableAccountName(),
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{key: string, name: string, account_name: string}>
     */
    private static function unitedStates(): array
    {
        $entries = [];

        foreach (Country::UnitedStates->regions() as $code => $state) {
            $entries[] = [
                'key' => 'US-'.$code,
                'name' => $state.' Department of Revenue',
                'account_name' => $state.' Sales Tax Payable',
            ];
        }

        return $entries;
    }
}
