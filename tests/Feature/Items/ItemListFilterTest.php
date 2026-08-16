<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use Livewire\Livewire;

/**
 * The Items list carries the same search + "Show inactive" filters as the Chart
 * of Accounts, so the two settings-style lists behave alike.
 */
beforeEach(function () {
    $user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);
    $this->actingAs($user);
});

test('the search matches on name', function () {
    Item::factory()->create(['name' => 'Cremation urn', 'sku' => 'URN-1']);
    Item::factory()->create(['name' => 'Graveside service', 'sku' => 'SVC-1']);

    Livewire::test('pages::settings.lists.items', ['company' => $this->company])
        ->set('search', 'urn')
        ->assertSee('Cremation urn')
        ->assertDontSee('Graveside service');
});

test('the search matches on SKU', function () {
    Item::factory()->create(['name' => 'Cremation urn', 'sku' => 'URN-1']);
    Item::factory()->create(['name' => 'Graveside service', 'sku' => 'SVC-1']);

    Livewire::test('pages::settings.lists.items', ['company' => $this->company])
        ->set('search', 'SVC-1')
        ->assertSee('Graveside service')
        ->assertDontSee('Cremation urn');
});

test('a search with no matches says so', function () {
    Item::factory()->create(['name' => 'Cremation urn', 'sku' => 'URN-1']);

    Livewire::test('pages::settings.lists.items', ['company' => $this->company])
        ->set('search', 'nothing-like-this')
        ->assertDontSee('Cremation urn')
        ->assertSee('No items match your search.');
});

test('inactive items are hidden until the switch is turned on', function () {
    Item::factory()->create(['name' => 'Active casket', 'is_active' => true]);
    Item::factory()->create(['name' => 'Retired casket', 'is_active' => false]);

    Livewire::test('pages::settings.lists.items', ['company' => $this->company])
        ->assertSee('Active casket')
        ->assertDontSee('Retired casket')
        ->set('showInactive', true)
        ->assertSee('Active casket')
        ->assertSee('Retired casket');
});

test('the search and the inactive switch compose', function () {
    Item::factory()->create(['name' => 'Retired urn', 'is_active' => false]);
    Item::factory()->create(['name' => 'Retired casket', 'is_active' => false]);

    Livewire::test('pages::settings.lists.items', ['company' => $this->company])
        ->set('showInactive', true)
        ->set('search', 'urn')
        ->assertSee('Retired urn')
        ->assertDontSee('Retired casket');
});

test('a list of only inactive items explains why it looks empty', function () {
    Item::factory()->create(['name' => 'Retired casket', 'is_active' => false]);

    Livewire::test('pages::settings.lists.items', ['company' => $this->company])
        ->assertSee('No active items.');
});

test('filtering resets pagination so the user lands on the first match', function () {
    foreach (range(1, 30) as $n) {
        Item::factory()->create(['name' => sprintf('Pageitem %02d', $n)]);
    }

    Livewire::test('pages::settings.lists.items', ['company' => $this->company])
        ->call('nextPage')
        ->assertSee('Pageitem 30')
        // Without a resetPage() the component would still be on page 2, which
        // holds none of the two matches — the user would see an empty table.
        ->set('search', 'Pageitem 01')
        ->assertSee('Pageitem 01');
});

test('the search is shareable via the URL', function () {
    Item::factory()->create(['name' => 'Cremation urn']);
    Item::factory()->create(['name' => 'Graveside service']);

    $this->get(route('lists.items', ['company' => $this->company->slug, 'q' => 'urn']))
        ->assertOk()
        ->assertSee('Cremation urn')
        ->assertDontSee('Graveside service');
});
