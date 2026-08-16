<?php

use App\Enums\AccountSubtype;
use App\Enums\AssetStatus;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Asset;
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

it('renders the assets index page', function () {
    $this->get(route('assets.index', ['company' => $this->company->slug]))->assertOk();
});

it('creates an asset via the form page', function () {
    Livewire::test('pages::assets.form', ['company' => $this->company])
        ->set('name', 'Laptop')
        ->set('asset_account_id', $this->fixedAssetAccount->id)
        ->set('acquired_date', '2026-01-15')
        ->set('cost', '1500.00')
        ->set('serial_number', 'SN-001')
        ->call('save')
        ->assertHasNoErrors();

    $asset = Asset::query()->where('name', 'Laptop')->first();

    expect($asset)->not->toBeNull()
        ->and($asset->company_id)->toBe($this->company->id)
        ->and($asset->cost_cents)->toBe(150000)
        ->and($asset->serial_number)->toBe('SN-001')
        ->and($asset->status)->toBe(AssetStatus::InService)
        ->and($asset->asset_no)->toStartWith('AST-');
});

it('auto-generates sequential asset numbers per company', function () {
    Livewire::test('pages::assets.form', ['company' => $this->company])
        ->set('name', 'First')
        ->set('asset_account_id', $this->fixedAssetAccount->id)
        ->set('acquired_date', '2026-01-15')
        ->set('cost', '100.00')
        ->call('save')
        ->assertHasNoErrors();

    $second = Livewire::test('pages::assets.form', ['company' => $this->company]);

    expect($second->get('asset_no'))->toBe('AST-000002');
});

it('requires a fixed-asset subtype account', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    Livewire::test('pages::assets.form', ['company' => $this->company])
        ->set('name', 'Bad account')
        ->set('asset_account_id', $bank->id)
        ->set('acquired_date', '2026-01-15')
        ->set('cost', '100.00')
        ->call('save')
        ->assertHasErrors(['asset_account_id']);
});

it('requires disposed_at when status is disposed', function () {
    Livewire::test('pages::assets.form', ['company' => $this->company])
        ->set('name', 'Disposed thing')
        ->set('asset_account_id', $this->fixedAssetAccount->id)
        ->set('acquired_date', '2026-01-15')
        ->set('cost', '100.00')
        ->set('status', AssetStatus::Disposed->value)
        ->call('save')
        ->assertHasErrors(['disposed_at']);
});

it('saves disposal date and notes when status is sold', function () {
    Livewire::test('pages::assets.form', ['company' => $this->company])
        ->set('name', 'Old van')
        ->set('asset_account_id', $this->fixedAssetAccount->id)
        ->set('acquired_date', '2024-01-15')
        ->set('cost', '20000.00')
        ->set('status', AssetStatus::Sold->value)
        ->set('disposed_at', '2026-05-01')
        ->set('disposal_notes', 'Sold to neighbor for $5,000')
        ->call('save')
        ->assertHasNoErrors();

    $asset = Asset::query()->where('name', 'Old van')->firstOrFail();

    expect($asset->status)->toBe(AssetStatus::Sold)
        ->and($asset->disposed_at->toDateString())->toBe('2026-05-01')
        ->and($asset->disposal_notes)->toBe('Sold to neighbor for $5,000');
});

it('isolates assets between companies', function () {
    $otherCompany = Company::factory()->create();
    $otherCompany->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $otherAsset = (function () use ($otherCompany) {
        app()->instance('current_company', $otherCompany);

        return Asset::factory()->create(['name' => 'Other Co Laptop']);
    })();

    app()->instance('current_company', $this->company);

    expect(Asset::query()->where('id', $otherAsset->id)->exists())->toBeFalse()
        ->and(Asset::query()->withoutGlobalScopes()->where('id', $otherAsset->id)->exists())->toBeTrue();
});

it('archives and restores an asset', function () {
    $asset = Asset::factory()->create([
        'name' => 'Printer',
        'asset_account_id' => $this->fixedAssetAccount->id,
    ]);

    Livewire::test('pages::assets.show', ['company' => $this->company, 'asset' => $asset])
        ->call('archive')
        ->assertHasNoErrors();

    expect($asset->fresh()->is_active)->toBeFalse();

    Livewire::test('pages::assets.show', ['company' => $this->company, 'asset' => $asset->fresh()])
        ->call('restore')
        ->assertHasNoErrors();

    expect($asset->fresh()->is_active)->toBeTrue();
});

it('soft-deletes an asset via the show page', function () {
    $asset = Asset::factory()->create([
        'name' => 'Trash',
        'asset_account_id' => $this->fixedAssetAccount->id,
    ]);

    Livewire::test('pages::assets.show', ['company' => $this->company, 'asset' => $asset])
        ->call('delete')
        ->assertHasNoErrors();

    $this->assertSoftDeleted('assets', ['id' => $asset->id]);
});

it('updates an existing asset', function () {
    $asset = Asset::factory()->create([
        'name' => 'Old name',
        'asset_account_id' => $this->fixedAssetAccount->id,
    ]);

    Livewire::test('pages::assets.form', ['company' => $this->company, 'asset' => $asset])
        ->set('name', 'New name')
        ->set('location', 'Warehouse B')
        ->call('save')
        ->assertHasNoErrors();

    expect($asset->fresh()->name)->toBe('New name')
        ->and($asset->fresh()->location)->toBe('Warehouse B');
});

it('filters and searches the index', function () {
    Asset::factory()->create(['name' => 'Apple laptop', 'asset_account_id' => $this->fixedAssetAccount->id]);
    Asset::factory()->create(['name' => 'Dell monitor', 'asset_account_id' => $this->fixedAssetAccount->id]);

    Livewire::test('pages::assets.index', ['company' => $this->company])
        ->set('search', 'Apple')
        ->assertSee('Apple laptop')
        ->assertDontSee('Dell monitor');
});
