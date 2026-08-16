<?php

use App\Enums\CompanyRole;
use App\Models\NavPreference;
use App\Models\User;
use App\Support\Navigation\SidebarNavCatalog;
use Livewire\Livewire;

beforeEach(function () {
    // The factory creates a personal company and switches the user to it; the
    // HTTP middleware binds that same company, so use it as our test company so
    // saved preferences and the rendered sidebar agree on company scope.
    $this->user = User::factory()->create();
    $this->company = $this->user->currentCompany;

    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('exposes gated groups and items in the catalog for an owner', function () {
    $keys = SidebarNavCatalog::flattenKeys($this->company, $this->user);

    expect($keys)->toHaveKey('banking')
        ->and($keys)->toHaveKey('banking.transfers')
        ->and($keys)->toHaveKey('reports.all_reports');
});

it('omits an item and its group gated off by a company feature flag', function () {
    $this->company->update(['features_inventory' => false]);

    $keys = SidebarNavCatalog::flattenKeys($this->company->fresh(), $this->user);

    expect($keys)->not->toHaveKey('inventory')
        ->and($keys)->not->toHaveKey('inventory.stock_on_hand');
});

it('dispatches a refresh event when saving', function () {
    Livewire::actingAs($this->user)
        ->test('pages::settings.navigation')
        ->set('visible.banking__transfers', false)
        ->call('save')
        ->assertDispatched('sidebar-nav-updated');
});

it('refreshes the sidebar-nav island when preferences change', function () {
    $component = Livewire::actingAs($this->user)
        ->test('sidebar-nav')
        ->assertSee('Transfers');

    NavPreference::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'item_key' => 'banking.transfers',
    ]);

    $component->dispatch('sidebar-nav-updated')
        ->assertDontSee('Transfers');
});

it('defaults Banking, Revenues and Purchases open when no expand cookie is set', function () {
    $open = Livewire::actingAs($this->user)->test('sidebar-nav')->instance()->openGroups();

    expect($open)->toEqualCanonicalizing(['banking', 'customers', 'vendors']);
});

it('respects an explicit expand cookie over the defaults', function () {
    $open = Livewire::actingAs($this->user)
        ->withCookie('sidebar_groups', 'reports')
        ->test('sidebar-nav')
        ->instance()->openGroups();

    expect($open)->toBe(['reports']);
});

it('saves a turned-off link as a nav preference', function () {
    Livewire::actingAs($this->user)
        ->test('pages::settings.navigation')
        ->set('visible.banking__transfers', false)
        ->call('save');

    $this->assertDatabaseHas('nav_preferences', [
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'item_key' => 'banking.transfers',
    ]);
});

it('removes a nav preference when the link is turned back on', function () {
    NavPreference::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'item_key' => 'banking.transfers',
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::settings.navigation')
        ->set('visible.banking__transfers', true)
        ->call('save');

    $this->assertDatabaseMissing('nav_preferences', [
        'item_key' => 'banking.transfers',
    ]);
});

it('clears all preferences on reset to defaults', function () {
    NavPreference::factory()->create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'item_key' => 'banking.transfers',
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::settings.navigation')
        ->call('resetToDefaults');

    expect(NavPreference::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('hides a turned-off link from the rendered sidebar', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('transfers.index'));

    NavPreference::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'item_key' => 'banking.transfers',
    ]);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('banking.register'))
        ->assertDontSee(route('transfers.index'));
});

it('hides a whole section when the group key is hidden', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-sidebar-group="banking"', escape: false);

    NavPreference::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'item_key' => 'banking',
    ]);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-sidebar-group="banking"', escape: false);
});

it('drops a group automatically when all its items are hidden', function () {
    foreach (['banking.bank_register', 'banking.reconcile', 'banking.import_statement', 'banking.rules', 'banking.cheques', 'banking.deposits', 'banking.transfers'] as $key) {
        NavPreference::create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'item_key' => $key,
        ]);
    }

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-sidebar-group="banking"', escape: false);
});

it('keeps preferences isolated per user', function () {
    $other = User::factory()->create();
    $this->company->members()->attach($other, ['role' => CompanyRole::Owner->value]);

    NavPreference::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'item_key' => 'banking.transfers',
    ]);

    expect(NavPreference::query()->where('user_id', $other->id)->count())->toBe(0);
});

it('reveals a hidden link when the show-all toggle is on, without deleting the preference', function () {
    NavPreference::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'item_key' => 'banking.transfers',
    ]);

    Livewire::actingAs($this->user)
        ->test('sidebar-nav')
        ->assertDontSee('Transfers')
        ->call('toggleShowAll')
        ->assertSet('showAll', true)
        ->assertSee('Transfers');

    // The toggle is a view-only reveal; the saved preference must survive.
    $this->assertDatabaseHas('nav_preferences', ['item_key' => 'banking.transfers']);
});

it('seeds the show-all toggle from its cookie so it survives navigation', function () {
    NavPreference::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'item_key' => 'banking.transfers',
    ]);

    Livewire::actingAs($this->user)
        ->withCookie('sidebar_show_all', '1')
        ->test('sidebar-nav')
        ->assertSet('showAll', true)
        ->assertSee('Transfers');
});

it('only renders the show-all toggle when something is hidden', function () {
    Livewire::actingAs($this->user)
        ->test('sidebar-nav')
        ->assertDontSee('Show all sections');

    NavPreference::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'item_key' => 'banking.transfers',
    ]);

    Livewire::actingAs($this->user)
        ->test('sidebar-nav')
        ->assertSee('Show all sections');
});
