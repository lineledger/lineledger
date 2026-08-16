<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\TaxAgency;
use App\Services\Migration\SystemAccountMapper;
use Illuminate\Database\Eloquent\Builder;

/**
 * @return Builder<Account>
 */
function companyAccountQuery(Company $company)
{
    return Account::withoutGlobalScopes()->where('company_id', $company->id);
}

function systemAccountOf(Company $company, AccountSubtype $subtype): ?Account
{
    return companyAccountQuery($company)->where('subtype', $subtype->value)->where('is_system', true)->first();
}

function makeImportedAccount(Company $company, string $code, string $name, AccountSubtype $subtype): Account
{
    return Account::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'code' => $code,
        'name' => $name,
        'type' => $subtype->type(),
        'subtype' => $subtype,
        'normal_balance' => $subtype->type()->normalBalance(),
        'is_system' => false,
        'is_active' => true,
    ]);
}

beforeEach(function () {
    // Factory creation seeds the full default chart (system accounts included).
    $this->company = Company::factory()->create(['address_country' => 'CA']);
});

test('re-pointing Accounts Receivable promotes the chosen account and demotes the old one', function () {
    $seededAr = systemAccountOf($this->company, AccountSubtype::AccountsReceivable);
    $imported = makeImportedAccount($this->company, '1101', 'A/R (QuickBooks)', AccountSubtype::AccountsReceivable);

    app(SystemAccountMapper::class)->commit($this->company, ['accounts_receivable' => $imported->id]);

    expect($imported->fresh()->is_system)->toBeTrue();
    expect($seededAr->fresh()->is_system)->toBeFalse();

    // The invariant posting relies on: exactly one is_system AR, and it's the new one.
    $live = systemAccountOf($this->company, AccountSubtype::AccountsReceivable);
    expect(companyAccountQuery($this->company)->where('subtype', AccountSubtype::AccountsReceivable->value)->where('is_system', true)->count())->toBe(1);
    expect($live->id)->toBe($imported->id);
});

test('re-pointing Opening Balance Equity renames by name and leaves is_system untouched', function () {
    $oldObe = companyAccountQuery($this->company)->where('name', 'Opening Balance Equity')->first();
    $chosen = makeImportedAccount($this->company, '3101', 'Opening Balances (QB)', AccountSubtype::Equity);

    app(SystemAccountMapper::class)->commit($this->company, ['opening_balance_equity' => $chosen->id]);

    expect($chosen->fresh()->name)->toBe('Opening Balance Equity');
    expect($chosen->fresh()->is_system)->toBeFalse();
    expect($oldObe->fresh()->name)->toBe('Opening Balance Equity (replaced)');
    // Exactly one account carries the canonical name the trial-balance importer looks for.
    expect(companyAccountQuery($this->company)->where('name', 'Opening Balance Equity')->count())->toBe(1);
});

test('re-pointing Inventory and COGS updates the company default account columns', function () {
    $importedInv = makeImportedAccount($this->company, '1401', 'Inventory (QB)', AccountSubtype::Inventory);
    $importedCogs = makeImportedAccount($this->company, '5001', 'COGS (QB)', AccountSubtype::CostOfGoodsSold);

    app(SystemAccountMapper::class)->commit($this->company, [
        'inventory' => $importedInv->id,
        'cogs' => $importedCogs->id,
    ]);

    $company = $this->company->fresh();
    expect($company->default_inventory_asset_account_id)->toBe($importedInv->id);
    expect($company->default_cogs_account_id)->toBe($importedCogs->id);
    expect($importedInv->fresh()->is_system)->toBeTrue();
    expect($importedCogs->fresh()->is_system)->toBeTrue();
});

test('re-pointing Sales Tax Payable repoints existing tax agencies', function () {
    $imported = makeImportedAccount($this->company, '2201', 'GST Payable (QB)', AccountSubtype::TaxPayable);

    app(SystemAccountMapper::class)->commit($this->company, ['tax_payable' => $imported->id]);

    $agencies = TaxAgency::withoutGlobalScopes()->where('company_id', $this->company->id)->get();
    expect($agencies)->not->toBeEmpty();
    $agencies->each(fn ($a) => expect($a->payable_account_id)->toBe($imported->id));
});

test('committing the current mapping is an idempotent no-op', function () {
    $seededAr = systemAccountOf($this->company, AccountSubtype::AccountsReceivable);

    $mapper = app(SystemAccountMapper::class);
    $mapper->commit($this->company, ['accounts_receivable' => $seededAr->id]);
    $mapper->commit($this->company, ['accounts_receivable' => $seededAr->id]);

    expect(companyAccountQuery($this->company)->where('subtype', AccountSubtype::AccountsReceivable->value)->where('is_system', true)->count())->toBe(1);
    expect(systemAccountOf($this->company, AccountSubtype::AccountsReceivable)->id)->toBe($seededAr->id);
});

test('mapping one account to two roles is rejected', function () {
    $seededAr = systemAccountOf($this->company, AccountSubtype::AccountsReceivable);

    expect(fn () => app(SystemAccountMapper::class)->commit($this->company, [
        'accounts_receivable' => $seededAr->id,
        'accounts_payable' => $seededAr->id,
    ]))->toThrow(InvalidArgumentException::class);
});

test('mapping a role to a different-subtype account re-types the chosen account', function () {
    $income = makeImportedAccount($this->company, '4999', 'Misc Income', AccountSubtype::Income);

    app(SystemAccountMapper::class)->commit($this->company, [
        'accounts_receivable' => $income->id,
    ]);

    $fresh = $income->fresh();
    expect($fresh->subtype)->toBe(AccountSubtype::AccountsReceivable)
        ->and($fresh->type)->toBe(AccountSubtype::AccountsReceivable->type())
        ->and($fresh->normal_balance)->toBe(AccountSubtype::AccountsReceivable->type()->normalBalance())
        ->and($fresh->is_system)->toBeTrue();
    expect(systemAccountOf($this->company, AccountSubtype::AccountsReceivable)->id)->toBe($income->id);
});

test('Retained Earnings can be pointed at an imported equity account, re-typing it', function () {
    $seededRe = systemAccountOf($this->company, AccountSubtype::RetainedEarnings);
    // Mirrors a QuickBooks "Retained Earnings" brought in under the Equity subtype.
    $qbRetained = makeImportedAccount($this->company, '32000', 'Retained Earnings', AccountSubtype::Equity);

    app(SystemAccountMapper::class)->commit($this->company, ['retained_earnings' => $qbRetained->id]);

    $fresh = $qbRetained->fresh();
    expect($fresh->subtype)->toBe(AccountSubtype::RetainedEarnings)
        ->and($fresh->is_system)->toBeTrue()
        ->and($seededRe->fresh()->is_system)->toBeFalse();
    expect(systemAccountOf($this->company, AccountSubtype::RetainedEarnings)->id)->toBe($qbRetained->id);
});
