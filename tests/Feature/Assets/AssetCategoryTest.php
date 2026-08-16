<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->fixedAssetAccount = Account::query()
        ->where('subtype', AccountSubtype::FixedAsset->value)
        ->where('name', 'Office Equipment')
        ->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('creates an asset category via the settings page', function () {
    Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Computers')
        ->set('f_default_asset_account_id', $this->fixedAssetAccount->id)
        ->set('f_default_useful_life_months', 36)
        ->call('save')
        ->assertHasNoErrors();

    $cat = AssetCategory::query()->where('name', 'Computers')->first();

    expect($cat)->not->toBeNull()
        ->and($cat->company_id)->toBe($this->company->id)
        ->and($cat->default_asset_account_id)->toBe($this->fixedAssetAccount->id)
        ->and($cat->default_useful_life_months)->toBe(36);
});

it('requires unique name per company', function () {
    AssetCategory::create(['name' => 'Vehicles', 'is_active' => true]);

    Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Vehicles')
        ->call('save')
        ->assertHasErrors(['f_name']);
});

it('rejects non-fixed-asset accounts for the default asset account', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Bad cat')
        ->set('f_default_asset_account_id', $bank->id)
        ->call('save')
        ->assertHasErrors(['f_default_asset_account_id']);
});

it('rejects non-expense accounts for default depreciation expense', function () {
    Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Bad cat 2')
        ->set('f_default_depreciation_expense_account_id', $this->fixedAssetAccount->id)
        ->call('save')
        ->assertHasErrors(['f_default_depreciation_expense_account_id']);
});

it('applies category defaults onto the asset form when category is selected', function () {
    $accumDep = Account::query()
        ->where('subtype', AccountSubtype::FixedAsset->value)
        ->where('name', 'Accumulated Depreciation')
        ->firstOrFail();
    $expense = Account::query()
        ->where('type', AccountType::Expense->value)
        ->orderBy('code')
        ->firstOrFail();

    $cat = AssetCategory::create([
        'name' => 'Computers',
        'default_asset_account_id' => $this->fixedAssetAccount->id,
        'default_accumulated_depreciation_account_id' => $accumDep->id,
        'default_depreciation_expense_account_id' => $expense->id,
        'default_useful_life_months' => 36,
        'is_active' => true,
    ]);

    $component = Livewire::test('pages::assets.form', ['company' => $this->company])
        ->set('asset_category_id', $cat->id);

    expect($component->get('asset_account_id'))->toBe($this->fixedAssetAccount->id)
        ->and($component->get('accumulated_depreciation_account_id'))->toBe($accumDep->id)
        ->and($component->get('depreciation_expense_account_id'))->toBe($expense->id)
        ->and($component->get('useful_life_months'))->toBe(36);

    $component
        ->set('name', 'New laptop')
        ->set('acquired_date', '2026-01-15')
        ->set('cost', '1500.00')
        ->call('save')
        ->assertHasNoErrors();

    $asset = Asset::query()->where('name', 'New laptop')->firstOrFail();

    expect($asset->asset_category_id)->toBe($cat->id)
        ->and($asset->accumulated_depreciation_account_id)->toBe($accumDep->id)
        ->and($asset->depreciation_expense_account_id)->toBe($expense->id)
        ->and($asset->useful_life_months)->toBe(36);
});

it('updates an asset category', function () {
    $cat = AssetCategory::create(['name' => 'Tools', 'is_active' => true]);

    Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->call('openEdit', $cat->id)
        ->set('f_name', 'Hand tools')
        ->set('f_is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($cat->fresh()->name)->toBe('Hand tools')
        ->and($cat->fresh()->is_active)->toBeFalse();
});
