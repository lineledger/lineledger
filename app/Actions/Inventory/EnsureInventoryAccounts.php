<?php

namespace App\Actions\Inventory;

use App\Actions\Payroll\EnsurePayrollAccounts;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;

/**
 * Ensures a company has the system Inventory Asset + Cost of Goods Sold accounts
 * that inventory postings resolve through company.default_inventory_asset_account_id
 * and default_cogs_account_id. The setup wizard seeds these only when the
 * inventory feature is selected; this backfills them — and wires up the company
 * default columns — when inventory is enabled later on a company that lacks them
 * (mirrors {@see EnsurePayrollAccounts}). Idempotent: matches
 * by code before creating, and never overwrites an existing default. Returns the
 * number of accounts created.
 */
final class EnsureInventoryAccounts
{
    public function handle(Company $company): int
    {
        $created = 0;
        $inventoryId = null;
        $cogsId = null;

        foreach ($company->jurisdiction->defaults()->coreAccounts() as $row) {
            if (! in_array($row['subtype'], [AccountSubtype::Inventory, AccountSubtype::CostOfGoodsSold], true)) {
                continue;
            }

            $account = Account::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('code', $row['code'])
                ->first();

            if ($account === null) {
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
                ]);

                $created++;
            }

            if ($row['subtype'] === AccountSubtype::Inventory) {
                $inventoryId = $account->id;
            } else {
                $cogsId = $account->id;
            }
        }

        // Posting resolves these by company column, not by a subtype scan, so wire
        // them up whenever they're missing — without clobbering a default the owner
        // may have re-pointed at a different account.
        $defaults = array_filter([
            'default_inventory_asset_account_id' => $company->default_inventory_asset_account_id ? null : $inventoryId,
            'default_cogs_account_id' => $company->default_cogs_account_id ? null : $cogsId,
        ]);

        if ($defaults !== []) {
            $company->forceFill($defaults)->saveQuietly();
        }

        return $created;
    }
}
