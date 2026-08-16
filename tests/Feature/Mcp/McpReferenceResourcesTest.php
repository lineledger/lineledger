<?php

declare(strict_types=1);

use App\Enums\Country;
use App\Enums\OrganizationType;
use App\Enums\TaxAppliesTo;
use App\Mcp\Resources\ContactsResource;
use App\Mcp\Resources\GifiCatalogResource;
use App\Mcp\Resources\ItemsResource;
use App\Mcp\Resources\TaxCodesResource;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\TaxAgency;
use App\Models\TaxCode;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
    Auth::forgetGuards();
});

it('RefResources: tax-codes resource lists agencies, codes, and rates', function () {
    $company = Company::factory()->create();
    $payable = Account::query()->firstOrFail();

    $agency = TaxAgency::create([
        'company_id' => $company->id,
        'name' => 'Test Tax Authority',
        'registration_number' => '123456789RT0001',
        'payable_account_id' => $payable->id,
        'is_active' => true,
    ]);
    TaxCode::create([
        'company_id' => $company->id,
        'code' => 'TST13',
        'name' => 'Test 13%',
        'rate_basis_points' => 1300,
        'agency_id' => $agency->id,
        'is_recoverable' => true,
        'applies_to' => TaxAppliesTo::Both->value,
        'is_active' => true,
    ]);

    bindMcpTenant($company);

    $response = (new TaxCodesResource)->handle(new Request([]));
    $content = (string) $response->content();

    expect($response->isError())->toBeFalse()
        ->and($content)->toContain('Test Tax Authority')
        ->and($content)->toContain('123456789RT0001')
        ->and($content)->toContain('TST13')
        ->and($content)->toContain('13%')
        ->and($content)->toContain('sales & purchases');
});

it('RefResources: tax-codes resource refuses a key without tax:read', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company, ['sales:read']);

    $response = (new TaxCodesResource)->handle(new Request([]));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('tax:read');
});

it('RefResources: items resource lists products and registers only when items exist', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);

    // No items yet -> the resource hides itself.
    expect((new ItemsResource)->shouldRegister(new Request([])))->toBeFalse();

    Item::factory()->tracked()->create([
        'company_id' => $company->id,
        'name' => 'Test Widget',
        'sku' => 'TW-1',
        'qty_on_hand_cached' => 7,
        'default_price_cents' => 2500,
    ]);

    expect((new ItemsResource)->shouldRegister(new Request([])))->toBeTrue();

    $content = (string) (new ItemsResource)->handle(new Request([]))->content();

    expect($content)->toContain('Test Widget')
        ->toContain('TW-1')
        ->toContain('on hand');
});

it('RefResources: contacts resource lists customers and vendors', function () {
    $company = Company::factory()->create();

    Contact::factory()->customer()->create([
        'company_id' => $company->id,
        'display_name' => 'Acme Customer Co',
    ]);
    Contact::factory()->vendor()->create([
        'company_id' => $company->id,
        'display_name' => 'Bolt Vendor Supplies',
    ]);

    bindMcpTenant($company);

    $content = (string) (new ContactsResource)->handle(new Request([]))->content();

    expect($content)->toContain('Acme Customer Co')
        ->toContain('Customer')
        ->toContain('Bolt Vendor Supplies')
        ->toContain('Vendor');
});

it('RefResources: GIFI catalog registers and renders for a Canadian GIFI filer', function () {
    $company = Company::factory()->create([
        'organization_type' => OrganizationType::Corporation->value,
    ]);
    bindMcpTenant($company);

    expect((new GifiCatalogResource)->shouldRegister(new Request([])))->toBeTrue();

    $content = (string) (new GifiCatalogResource)->handle(new Request([]))->content();

    expect($content)->toContain('1001')
        ->toContain('Cash and deposits')
        ->toContain('Balance Sheet');
});

it('RefResources: GIFI catalog is not advertised to a US company', function () {
    $company = Company::factory()->forCountry(Country::UnitedStates)->create([
        'organization_type' => OrganizationType::Corporation->value,
    ]);
    bindMcpTenant($company);

    expect((new GifiCatalogResource)->shouldRegister(new Request([])))->toBeFalse();
});
