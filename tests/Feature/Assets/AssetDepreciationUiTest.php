<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Company;
use App\Models\User;
use App\Services\Assets\DepreciationGenerator;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-05-24 12:00:00');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->fixedAssetAccount = Account::query()
        ->where('subtype', AccountSubtype::FixedAsset->value)
        ->where('name', 'Office Equipment')
        ->firstOrFail();
    $this->accumDep = Account::query()
        ->where('subtype', AccountSubtype::FixedAsset->value)
        ->where('name', 'Accumulated Depreciation')
        ->firstOrFail();
    $this->depExpense = Account::query()
        ->where('subtype', AccountSubtype::Expense->value)
        ->orderBy('code')
        ->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
    CarbonImmutable::setTestNow();
});

it('persists the auto-depreciate toggle from the form', function () {
    Livewire::test('pages::assets.form', ['company' => $this->company])
        ->set('name', 'Delivery van')
        ->set('asset_account_id', $this->fixedAssetAccount->id)
        ->set('acquired_date', '2026-01-15')
        ->set('in_service_date', '2026-01-15')
        ->set('cost', '3600.00')
        ->set('useful_life_months', 36)
        ->set('accumulated_depreciation_account_id', $this->accumDep->id)
        ->set('depreciation_expense_account_id', $this->depExpense->id)
        ->set('auto_depreciate', true)
        ->call('save')
        ->assertHasNoErrors();

    $asset = Asset::query()->where('name', 'Delivery van')->firstOrFail();

    expect($asset->auto_depreciate)->toBeTrue();

    // ...and the edit form can switch it back off.
    Livewire::test('pages::assets.form', ['company' => $this->company, 'asset' => $asset])
        ->set('auto_depreciate', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($asset->fresh()->auto_depreciate)->toBeFalse();
});

it('rejects enabling auto-depreciation without its prerequisites', function () {
    Livewire::test('pages::assets.form', ['company' => $this->company])
        ->set('name', 'No prerequisites')
        ->set('asset_account_id', $this->fixedAssetAccount->id)
        ->set('acquired_date', '2026-01-15')
        ->set('cost', '100.00')
        ->set('auto_depreciate', true)
        ->call('save')
        ->assertHasErrors([
            'in_service_date',
            'useful_life_months',
            'accumulated_depreciation_account_id',
            'depreciation_expense_account_id',
        ]);

    expect(Asset::query()->count())->toBe(0);
});

it('renders the depreciation card with statuses and net book value', function () {
    $this->company->update(['lock_date' => '2026-01-31']);

    $asset = Asset::factory()->create([
        'asset_account_id' => $this->fixedAssetAccount->id,
        'accumulated_depreciation_account_id' => $this->accumDep->id,
        'depreciation_expense_account_id' => $this->depExpense->id,
        'in_service_date' => '2026-01-15',
        'cost_cents' => 360000,
        'salvage_value_cents' => 0,
        'useful_life_months' => 36,
        'auto_depreciate' => true,
    ]);

    // January is locked; February–April generate. Post February's draft so the
    // card shows Posted + Draft + Pending + Locked all at once.
    $created = app(DepreciationGenerator::class)
        ->generateDue($this->company, CarbonImmutable::parse('2026-05-24'));

    expect($created)->toHaveCount(3);

    app(JournalPoster::class)->post($created->first());

    Livewire::test('pages::assets.show', ['company' => $this->company, 'asset' => $asset->fresh()])
        ->assertSeeHtml('data-test="asset-depreciation-schedule"')
        ->assertSee('Auto-depreciation on')
        ->assertSee('Accumulated (as generated)')
        ->assertSee('Net book value')
        ->assertSee('3,500.00') // 360000¢ cost − 10000¢ posted
        ->assertSee('Locked — record manually')
        ->assertSee('Posted')
        ->assertSee('Draft')
        ->assertSee('Pending');
});
