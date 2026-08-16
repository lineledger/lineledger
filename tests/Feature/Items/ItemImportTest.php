<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\ItemType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\TaxCode;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);

    // Deterministic accounts to reference by code (avoids depending on which
    // codes the seeded chart happens to use).
    $this->income = makeItemTestAccount('9001', 'Test Sales', AccountSubtype::Income);
    $this->invAsset = makeItemTestAccount('9002', 'Test Inventory', AccountSubtype::Inventory);
    $this->cogs = makeItemTestAccount('9003', 'Test COGS', AccountSubtype::CostOfGoodsSold);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makeItemTestAccount(string $code, string $name, AccountSubtype $subtype): Account
{
    return Account::create([
        'code' => $code,
        'name' => $name,
        'type' => $subtype->type(),
        'subtype' => $subtype,
        'normal_balance' => $subtype->type()->normalBalance(),
        'is_active' => true,
    ]);
}

function itemsCsvUpload(string $body): UploadedFile
{
    return UploadedFile::fake()->createWithContent('items.csv', $body);
}

it('streams a downloadable items template', function () {
    $response = $this->get(route('lists.template', ['company' => $this->company->slug, 'list' => 'items']));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    expect($response->streamedContent())->toContain('sku,name,description,type,item_category');
});

it('imports an item, resolving accounts, type, tax code, and auto-creating its category', function () {
    $taxCode = TaxCode::query()->where('is_active', true)->firstOrFail();

    $csv = "sku,name,description,type,item_category,is_inventory,income_account_code,expense_account_code,inventory_asset_account_code,cogs_account_code,default_price,default_tax_code,reorder_point\n"
        ."SVC-1,Consulting,Hourly,service,Brand New Cat,no,9001,,,,150.00,{$taxCode->code},\n";

    $component = Livewire::test('pages::settings.lists.items', ['company' => $this->company])
        ->set('importFile', itemsCsvUpload($csv))
        ->call('previewImport');

    expect($component->get('importErrors'))->toBe([]);
    expect(collect($component->get('importPreviewRows'))->where('action', 'create'))->toHaveCount(1);

    $component->call('runImport');

    $item = Item::query()->where('sku', 'SVC-1')->first();
    expect($item)->not->toBeNull();
    expect($item->type)->toBe(ItemType::Service);
    expect($item->income_account_id)->toBe($this->income->id);
    expect($item->default_tax_code_id)->toBe($taxCode->id);
    expect($item->default_price_cents)->toBe(15000);

    // The referenced category did not exist and was auto-created.
    $category = ItemCategory::query()->where('name', 'Brand New Cat')->first();
    expect($category)->not->toBeNull();
    expect($item->item_category_id)->toBe($category->id);
});

it('imports an inventory item with its inventory and COGS accounts', function () {
    $csv = "sku,name,description,type,item_category,is_inventory,income_account_code,expense_account_code,inventory_asset_account_code,cogs_account_code,default_price,default_tax_code,reorder_point\n"
        ."WIDGET-1,Widget,,inventory,,yes,9001,,9002,9003,49.99,,5\n";

    Livewire::test('pages::settings.lists.items', ['company' => $this->company])
        ->set('importFile', itemsCsvUpload($csv))
        ->call('runImport');

    $item = Item::query()->where('sku', 'WIDGET-1')->first();
    expect($item)->not->toBeNull();
    expect($item->type)->toBe(ItemType::Inventory);
    expect($item->track_inventory)->toBeTrue();
    expect($item->inventory_asset_account_id)->toBe($this->invAsset->id);
    expect($item->cogs_account_id)->toBe($this->cogs->id);
});

it('skips an item whose SKU already exists', function () {
    Item::create([
        'name' => 'Existing Item',
        'sku' => 'EXIST-1',
        'type' => ItemType::Service,
        'income_account_id' => $this->income->id,
        'default_price_cents' => 0,
        'is_active' => true,
    ]);

    $csv = "sku,name,description,type,item_category,is_inventory,income_account_code,expense_account_code,inventory_asset_account_code,cogs_account_code,default_price,default_tax_code,reorder_point\n"
        ."EXIST-1,Renamed By Import,,service,,no,9001,,,,10.00,,\n"
        ."NEW-1,Fresh Item,,service,,no,9001,,,,20.00,,\n";

    $component = Livewire::test('pages::settings.lists.items', ['company' => $this->company])
        ->set('importFile', itemsCsvUpload($csv))
        ->call('previewImport');

    expect(collect($component->get('importPreviewRows'))->where('action', 'create'))->toHaveCount(1);
    expect($component->get('importSummary')['skipped_existing'])->toBe(1);

    $component->call('runImport');

    expect(Item::query()->where('sku', 'EXIST-1')->count())->toBe(1);
    expect(Item::query()->where('sku', 'EXIST-1')->first()->name)->toBe('Existing Item');
    expect(Item::query()->where('sku', 'NEW-1')->exists())->toBeTrue();
});

it('errors and creates nothing when an account code is unknown', function () {
    $csv = "sku,name,description,type,item_category,is_inventory,income_account_code,expense_account_code,inventory_asset_account_code,cogs_account_code,default_price,default_tax_code,reorder_point\n"
        ."BAD-1,Bad Account Item,,service,,no,9999,,,,10.00,,\n";

    $component = Livewire::test('pages::settings.lists.items', ['company' => $this->company])
        ->set('importFile', itemsCsvUpload($csv))
        ->call('runImport');

    expect($component->get('importErrors'))->not->toBe([]);
    expect(Item::query()->where('sku', 'BAD-1')->exists())->toBeFalse();
});
