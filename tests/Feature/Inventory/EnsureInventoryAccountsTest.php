<?php

use App\Actions\Inventory\EnsureInventoryAccounts;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Simulate a company created with inventory unchecked: no Inventory Asset / COGS
 * accounts and no default columns wired.
 */
function stripInventoryCore(Company $company): void
{
    // forceDelete, not delete: the gated wizard never *creates* these rows, so
    // simulate their absence rather than a soft-delete the backfill would still see.
    Account::withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->whereIn('subtype', [AccountSubtype::Inventory->value, AccountSubtype::CostOfGoodsSold->value])
        ->forceDelete();

    $company->forceFill([
        'default_inventory_asset_account_id' => null,
        'default_cogs_account_id' => null,
    ])->saveQuietly();
}

test('it backfills the Inventory Asset and COGS accounts and wires the company defaults', function () {
    stripInventoryCore($this->company);

    $created = app(EnsureInventoryAccounts::class)->handle($this->company);

    expect($created)->toBe(2);

    $inventory = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', '1400')->first();
    $cogs = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', '5000')->first();

    expect($inventory)->not->toBeNull()
        ->and($inventory->subtype)->toBe(AccountSubtype::Inventory)
        ->and($inventory->is_system)->toBeTrue();
    expect($cogs)->not->toBeNull()
        ->and($cogs->subtype)->toBe(AccountSubtype::CostOfGoodsSold)
        ->and($cogs->is_system)->toBeTrue();

    $this->company->refresh();
    expect($this->company->default_inventory_asset_account_id)->toBe($inventory->id);
    expect($this->company->default_cogs_account_id)->toBe($cogs->id);
});

test('it is idempotent on a company that already has the inventory accounts', function () {
    // The factory company already has 1400/5000 with defaults wired by the observer.
    $originalInventoryId = $this->company->default_inventory_asset_account_id;
    $originalCogsId = $this->company->default_cogs_account_id;

    expect($originalInventoryId)->not->toBeNull();

    $created = app(EnsureInventoryAccounts::class)->handle($this->company);

    expect($created)->toBe(0);

    $this->company->refresh();
    expect($this->company->default_inventory_asset_account_id)->toBe($originalInventoryId);
    expect($this->company->default_cogs_account_id)->toBe($originalCogsId);
});
