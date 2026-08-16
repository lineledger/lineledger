<?php

namespace App\Support\Defaults;

use App\Enums\AccountSubtype;

interface CompanyDefaults
{
    /**
     * Full starter chart of accounts to seed for a new company in this
     * jurisdiction. Used by non-wizard creation (factories, seeders, the
     * legacy path). The setup wizard composes its own list via
     * ChartTemplateBuilder instead.
     *
     * The setup wizard's "copy" mode also routes its rows through this contract
     * (via the transient pendingChartAccounts hand-off the observer reads), so
     * the shape optionally carries gifi_code and parent_code; the jurisdiction
     * defaults below simply never populate them.
     *
     * @return list<array{code: string, name: string, subtype: AccountSubtype, is_system?: bool, description?: string, gifi_code?: string, parent_code?: string}>
     */
    public function accounts(): array;

    /**
     * The jurisdiction "core": system/control accounts plus one bank and
     * Opening Balance Equity. This is the minimal chart that still satisfies
     * the posting engine and the observer's tax/inventory seeding. Naming is
     * jurisdiction-specific (Chequing vs Checking, GST/HST vs Sales Tax).
     *
     * @return list<array{code: string, name: string, subtype: AccountSubtype, is_system?: bool, description?: string}>
     */
    public function coreAccounts(): array;

    /**
     * Payment methods to seed for a new company in this jurisdiction.
     *
     * @return list<array{name: string, is_cheque: bool}>
     */
    public function paymentMethods(): array;

    /**
     * Tax agencies to seed. The first agency is treated as the default agency
     * for the tax codes below. Each agency's payable_account_id is resolved
     * separately by the observer (using the first TaxPayable account).
     *
     * @return list<array{name: string}>
     */
    public function taxAgencies(): array;

    /**
     * Tax codes to seed under the default tax agency. Returns an empty list
     * for jurisdictions where the user is expected to define their own codes.
     *
     * @return list<array{code: string, name: string, rate_basis_points: int, recoverable: bool}>
     */
    public function taxCodes(): array;
}
