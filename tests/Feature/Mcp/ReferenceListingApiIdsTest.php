<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Enums\TaxAppliesTo;
use App\Mcp\Concerns\ProposesWrites;
use App\Mcp\Resources\ContactsResource;
use App\Mcp\Resources\ItemsResource;
use App\Mcp\Resources\PaymentMethodsResource;
use App\Mcp\Resources\TaxCodesResource;
use App\Mcp\Servers\BusinessQaServer;
use App\Mcp\Tools\ContactsDirectoryTool;
use App\Mcp\Tools\ItemsCatalogTool;
use App\Mcp\Tools\PaymentMethodsTool;
use App\Mcp\Tools\TaxCodesTool;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\PaymentMethod;
use App\Models\TaxAgency;
use App\Models\TaxCode;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;

/**
 * Every reference listing reports the surrogate id an API caller must pass —
 * item_id, tax_code_id, contact_id, payment_method_id — because the user-facing
 * code/SKU/name can be changed and the id cannot. Each listing is exposed as a
 * tool as well as a resource, since many MCP clients only auto-surface tools.
 */
afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
    Auth::forgetGuards();
});

it('ApiIds: the items listing carries item_id and the tool matches the resource', function () {
    $company = Company::factory()->create();
    $item = Item::factory()->create(['company_id' => $company->id, 'name' => 'Test Widget', 'sku' => 'TW-1']);
    bindMcpTenant($company);

    $resourceText = (string) (new ItemsResource)->handle(new Request([]))->content();
    $toolText = (string) (new ItemsCatalogTool)->handle(new Request([]))->content();

    expect($resourceText)->toContain("API id {$item->id}")
        ->and($resourceText)->toContain('stable numeric item_id')
        ->and($toolText)->toBe($resourceText);
});

it('ApiIds: the tax listing carries both the tax_code_id and the agency id', function () {
    $company = Company::factory()->create();
    $agency = TaxAgency::create([
        'company_id' => $company->id,
        'name' => 'Test Tax Authority',
        'payable_account_id' => Account::query()->firstOrFail()->id,
        'is_active' => true,
    ]);
    $code = TaxCode::create([
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

    $resourceText = (string) (new TaxCodesResource)->handle(new Request([]))->content();
    $toolText = (string) (new TaxCodesTool)->handle(new Request([]))->content();

    expect($resourceText)->toContain("Test Tax Authority (API id {$agency->id})")
        ->and($resourceText)->toContain("API id {$code->id}")
        ->and($resourceText)->toContain('stable numeric tax_code_id')
        // The two id kinds are distinguished, or a caller would send an agency id
        // where a tax_code_id belongs.
        ->and($resourceText)->toContain('`agency_id`')
        ->and($toolText)->toBe($resourceText);
});

it('ApiIds: the contacts listing carries contact_id and the tool matches the resource', function () {
    $company = Company::factory()->create();
    $contact = Contact::factory()->customer()->create([
        'company_id' => $company->id,
        'display_name' => 'Acme Customer Co',
    ]);
    bindMcpTenant($company);

    $resourceText = (string) (new ContactsResource)->handle(new Request([]))->content();
    $toolText = (string) (new ContactsDirectoryTool)->handle(new Request([]))->content();

    expect($resourceText)->toContain("API id {$contact->id}")
        ->and($resourceText)->toContain('stable numeric contact_id')
        ->and($toolText)->toBe($resourceText);
});

it('ApiIds: the payment-methods listing carries payment_method_id and flags cheque methods', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);

    // Payment methods are seeded with the company, so there is always a list.
    $cheque = PaymentMethod::query()->where('is_cheque', true)->firstOrFail();

    $resourceText = (string) (new PaymentMethodsResource)->handle(new Request([]))->content();
    $toolText = (string) (new PaymentMethodsTool)->handle(new Request([]))->content();

    expect($resourceText)->toContain("API id {$cheque->id}")
        ->and($resourceText)->toContain('stable numeric payment_method_id')
        ->and($resourceText)->toContain('cheque')
        ->and($toolText)->toBe($resourceText);
});

it('ApiIds: payment methods refuse a key without settings:read', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company, ['sales:read']);

    $response = (new PaymentMethodsTool)->handle(new Request([]));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('settings:read');
});

it('ApiIds: payment methods refuse an OAuth member without the Lists section', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->memberships()->create([
        'user_id' => $user->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Banking->value],
    ]);
    app()->instance('current_company', $company);
    Auth::guard('api')->setUser($user);

    expect((new PaymentMethodsResource)->handle(new Request([]))->isError())->toBeTrue()
        ->and((new PaymentMethodsTool)->handle(new Request([]))->isError())->toBeTrue();
});

it('ApiIds: payment methods allow an OAuth member granted the Lists section', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->memberships()->create([
        'user_id' => $user->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Lists->value],
    ]);
    app()->instance('current_company', $company);
    Auth::guard('api')->setUser($user);

    expect((new PaymentMethodsTool)->handle(new Request([]))->isError())->toBeFalse();
});

it('ApiIds: the listed contact id is the one the propose-* tools resolve by', function () {
    $company = Company::factory()->create();
    $contact = Contact::factory()->customer()->create([
        'company_id' => $company->id,
        'display_name' => 'Acme Customer Co',
    ]);
    bindMcpTenant($company);

    $text = (string) (new ContactsDirectoryTool)->handle(new Request([]))->content();

    preg_match('/Acme Customer Co.*?API id (\d+)/', $text, $m);

    $resolver = new class
    {
        use ProposesWrites;

        public function resolve(mixed $ref): ?Contact
        {
            return $this->resolveContact($ref);
        }
    };

    expect($m[1] ?? null)->not->toBeNull()
        ->and($resolver->resolve($m[1])?->id)->toBe($contact->id);
});

it('ApiIds: only the ids the write tools actually accept are advertised to them', function () {
    $company = Company::factory()->create();
    Item::factory()->create(['company_id' => $company->id]);
    Contact::factory()->customer()->create(['company_id' => $company->id]);
    bindMcpTenant($company);

    $items = (string) (new ItemsCatalogTool)->handle(new Request([]))->content();
    $methods = (string) (new PaymentMethodsTool)->handle(new Request([]))->content();
    $contacts = (string) (new ContactsDirectoryTool)->handle(new Request([]))->content();

    // The propose-* tools resolve accounts, contacts, and tax codes — they take no
    // item or payment-method id, so pointing an agent at them there is a dead end.
    expect($items)->not->toContain('propose-*')
        ->and($methods)->not->toContain('propose-*')
        ->and($contacts)->toContain('propose-*');
});

it('ApiIds: the reference tools keep the names docs/api-v1.md points callers at', function () {
    // Renaming a class renames its MCP tool, which silently breaks both the docs
    // and any client that calls the tool by name.
    expect((new ItemsCatalogTool)->name())->toBe('items-catalog-tool')
        ->and((new TaxCodesTool)->name())->toBe('tax-codes-tool')
        ->and((new ContactsDirectoryTool)->name())->toBe('contacts-directory-tool')
        ->and((new PaymentMethodsTool)->name())->toBe('payment-methods-tool');
});

it('ApiIds: every reference listing is registered as a tool as well as a resource', function () {
    $server = new ReflectionClass(BusinessQaServer::class);
    $tools = $server->getDefaultProperties()['tools'];
    $resources = $server->getDefaultProperties()['resources'];

    // Clients that only surface tools must still reach every listing.
    expect($tools)->toContain(ItemsCatalogTool::class)
        ->toContain(TaxCodesTool::class)
        ->toContain(ContactsDirectoryTool::class)
        ->toContain(PaymentMethodsTool::class)
        ->and($resources)->toContain(PaymentMethodsResource::class);
});
