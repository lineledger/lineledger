<?php

use App\Actions\MasterData\SaveItem;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\ItemType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->invAsset = Account::query()->where('subtype', AccountSubtype::Inventory->value)->firstOrFail();
    $this->cogs = Account::query()->where('subtype', AccountSubtype::CostOfGoodsSold->value)->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makeServiceItem(string $name, int $priceCents = 0): Item
{
    return app(SaveItem::class)->handle([
        'name' => $name,
        'type' => 'service',
        'income_account_id' => test()->income->id,
        'default_price_cents' => $priceCents,
    ]);
}

it('saves an item with a type and category', function () {
    $category = ItemCategory::create(['name' => 'Widgets']);

    $item = app(SaveItem::class)->handle([
        'name' => 'Consulting',
        'type' => 'service',
        'item_category_id' => $category->id,
        'income_account_id' => $this->income->id,
    ]);

    expect($item->type)->toBe(ItemType::Service)
        ->and($item->item_category_id)->toBe($category->id)
        ->and($item->track_inventory)->toBeFalse();
});

it('derives inventory tracking from the Inventory type', function () {
    $item = app(SaveItem::class)->handle([
        'name' => 'Widget',
        'type' => 'inventory',
        'income_account_id' => $this->income->id,
        'inventory_asset_account_id' => $this->invAsset->id,
        'cogs_account_id' => $this->cogs->id,
    ]);

    expect($item->type)->toBe(ItemType::Inventory)
        ->and($item->track_inventory)->toBeTrue();
});

it('keeps the API track_inventory path working by deriving the type', function () {
    $item = app(SaveItem::class)->handle([
        'name' => 'Legacy widget',
        'track_inventory' => true,
        'income_account_id' => $this->income->id,
        'inventory_asset_account_id' => $this->invAsset->id,
        'cogs_account_id' => $this->cogs->id,
    ]);

    expect($item->track_inventory)->toBeTrue()
        ->and($item->type)->toBe(ItemType::Inventory);
});

it('saves and clears bundle components', function () {
    $a = makeServiceItem('Part A');
    $b = makeServiceItem('Part B');

    $bundle = app(SaveItem::class)->handle([
        'name' => 'Combo',
        'type' => 'bundle',
        'income_account_id' => $this->income->id,
        'components' => [
            ['component_item_id' => $a->id, 'quantity' => '2'],
            ['component_item_id' => $b->id, 'quantity' => '1'],
        ],
    ]);

    expect($bundle->components()->count())->toBe(2);

    // Switching the type away from bundle clears the components.
    $bundle = app(SaveItem::class)->handle([
        'name' => 'Combo',
        'type' => 'service',
        'income_account_id' => $this->income->id,
    ], $bundle);

    expect($bundle->components()->count())->toBe(0);
});

it('expands a bundle into component lines on the invoice form', function () {
    $a = makeServiceItem('Part A', 1000);
    $b = makeServiceItem('Part B', 2500);

    $bundle = app(SaveItem::class)->handle([
        'name' => 'Combo',
        'type' => 'bundle',
        'income_account_id' => $this->income->id,
        'components' => [
            ['component_item_id' => $a->id, 'quantity' => '2'],
            ['component_item_id' => $b->id, 'quantity' => '1'],
        ],
    ]);

    $component = Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('lines.0.item_id', $bundle->id);

    $lines = $component->get('lines');

    expect($lines)->toHaveCount(2)
        ->and((int) $lines[0]['item_id'])->toBe($a->id)
        ->and($lines[0]['quantity'])->toBe('2')
        ->and((int) $lines[1]['item_id'])->toBe($b->id);
});
