<?php

use App\Actions\Access\AssignMemberRoles;
use App\Actions\Access\SaveRole;
use App\Enums\Activity;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Enums\PermissionAction;
use App\Enums\PredefinedRole;
use App\Mcp\Tools\ChartOfAccountsTool;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\DailyInsight;
use App\Models\User;
use App\Services\GlobalSearch;
use App\Services\Insights\InsightDetectorRegistry;
use App\Support\Access\Grant;
use App\Support\Access\RecordRedirect;
use App\Support\Navigation\SidebarNavCatalog;
use Illuminate\Support\Facades\Notification;
use Laravel\Mcp\Request;
use Livewire\Livewire;

/**
 * What a role can't use isn't shown: sidebar links, buttons, balances, search
 * groups — on the web, through the API and over MCP.
 */
beforeEach(function () {
    Notification::fake();
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->owner = User::factory()->create();
    $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => CompanyRole::Owner]);

    // A custom role that may open the chart of accounts, but not see balances.
    $this->accountsClerk = function (): User {
        $role = app(SaveRole::class)->handle($this->company, $this->owner, null, 'Chart clerk', null, [
            Activity::Accounts->value => Grant::partial(PermissionAction::View, PermissionAction::Create),
        ]);
        $user = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $user->id, 'role' => CompanyRole::Custom, 'sections' => []]);
        app(AssignMemberRoles::class)->handle($this->company, $this->owner, $user, [$role->id]);

        return $user;
    };
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

it('leaves sidebar links out when the role can\'t open them', function () {
    $sales = predefinedRoleMember($this->company, PredefinedRole::Sales);

    $keys = array_keys(SidebarNavCatalog::flattenKeys($this->company, $sales));

    expect($keys)->toContain('sales.invoices', 'sales.estimates')
        ->not->toContain('sales.credit_memos', 'sales.invoice_templates', 'purchases.bills', 'banking.bank_register');

    expect(array_keys(SidebarNavCatalog::flattenKeys($this->company, $this->owner)))->toContain('sales.credit_memos', 'purchases.bills');
});

it('shows each dashboard figure only to a member allowed it', function () {
    $sales = predefinedRoleMember($this->company, PredefinedRole::Sales);

    Livewire::actingAs($sales)->test('pages::dashboard.index', ['company' => $this->company])
        ->assertSee(__('Accounts receivable'))
        ->assertDontSee(__('Cash on hand'))
        ->assertDontSee(__('Accounts payable'))
        ->assertDontSee(__('Net income (MTD)'))
        ->assertSet('sees', ['cash' => false, 'receivables' => true, 'payables' => false, 'income' => false]);

    Livewire::actingAs($this->owner)->test('pages::dashboard.index', ['company' => $this->company])
        ->assertSee(__('Cash on hand'))
        ->assertSee(__('Net income (MTD)'));
});

it('leaves the balance column off the chart of accounts without View balance', function () {
    $clerk = ($this->accountsClerk)();

    $page = Livewire::actingAs($clerk)->test('pages::accounts.index', ['company' => $this->company])
        ->assertDontSeeHtml('data-test="accounts-column-balance"');

    expect($page->instance()->balances())->toBe([]);

    Livewire::actingAs($this->owner)->test('pages::accounts.index', ['company' => $this->company])
        ->assertSeeHtml('data-test="accounts-column-balance"');
});

it('keeps balances and unreachable groups out of global search', function () {
    $clerk = ($this->accountsClerk)();
    $cash = Account::query()->where('code', '1000')->firstOrFail();
    $cash->forceFill(['balance_cents' => 123_456])->save();

    $forClerk = app(GlobalSearch::class)->search($cash->code, $clerk);
    $forOwner = app(GlobalSearch::class)->search($cash->code, $this->owner);

    expect($forClerk['accounts']->first()->amountCents)->toBeNull()
        ->and($forOwner['accounts']->first()->amountCents)->toBe(123_456);

    editLockDraftInvoice($this->company)->forceFill(['invoice_no' => 'ZZ-77', 'status' => InvoiceStatus::Posted])->save();
    $sales = predefinedRoleMember($this->company, PredefinedRole::Sales);

    $results = app(GlobalSearch::class)->search('ZZ-77', $sales);
    expect($results)->toHaveKey('invoices')->not->toHaveKey('credit_memos')->not->toHaveKey('bills');
});

it('nulls account balances for an API key whose creator may not see them', function () {
    $clerk = ($this->accountsClerk)();
    $token = CompanyApiKey::mint($this->company, 'Clerk key', $clerk->id)['plaintext'];
    $ownerToken = CompanyApiKey::mint($this->company, 'Owner key', $this->owner->id)['plaintext'];
    app()->forgetInstance('current_company');

    $this->withToken($token)->getJson('/api/v1/accounts')
        ->assertOk()
        ->assertJsonPath('data.0.balance_cents', null);

    expect($this->withToken($ownerToken)->getJson('/api/v1/accounts')->json('data.0.balance_cents'))->toBeInt();
});

it('lists the chart of accounts over MCP without balances the connection may not see', function () {
    $clerk = ($this->accountsClerk)();
    ['key' => $key] = CompanyApiKey::mint($this->company, 'Clerk key', $clerk->id);
    app()->instance('current_api_key', $key);

    $text = (string) (new ChartOfAccountsTool)->handle(new Request([]))->content();

    expect($text)->toContain('balances are not shown')
        ->not->toContain('balances as of');
});

it('sends a member who may create but not modify to the show page after saving', function () {
    $this->actingAs($this->owner);
    expect(RecordRedirect::route('invoices.edit'))->toBe('invoices.edit');

    $creator = User::factory()->create();
    $role = app(SaveRole::class)->handle($this->company, $this->owner, null, 'Invoice entry', null, [
        Activity::Invoices->value => Grant::partial(PermissionAction::View, PermissionAction::Create),
        Activity::Customers->value => Grant::partial(PermissionAction::View),
    ]);
    $this->company->memberships()->create(['user_id' => $creator->id, 'role' => CompanyRole::Custom, 'sections' => []]);
    app(AssignMemberRoles::class)->handle($this->company, $this->owner, $creator, [$role->id]);

    $this->actingAs($creator);
    expect(RecordRedirect::route('invoices.edit'))->toBe('invoices.show');
});

it('hides edit and void on an invoice from a member who may only view it', function () {
    $invoice = editLockDraftInvoice($this->company);
    $invoice->forceFill(['status' => InvoiceStatus::Posted])->save();
    $viewer = predefinedRoleMember($this->company, PredefinedRole::ViewOnly);

    Livewire::actingAs($viewer)->test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
        ->assertDontSeeHtml('data-test="edit-invoice-button"')
        ->assertDontSeeHtml('data-test="void-invoice-button"');

    Livewire::actingAs($this->owner)->test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
        ->assertSeeHtml('data-test="edit-invoice-button"')
        ->assertSeeHtml('data-test="void-invoice-button"');
});

it('shows an insight only to a member who could open the report behind it', function () {
    $today = $this->company->currentDateTime()->toDateString();
    DailyInsight::factory()->create(['company_id' => $this->company->id, 'insight_date' => $today, 'type' => 'overdue-receivables', 'headline' => 'Three invoices are overdue']);
    $sales = predefinedRoleMember($this->company, PredefinedRole::Sales);
    $banking = predefinedRoleMember($this->company, PredefinedRole::Banking);

    expect(InsightDetectorRegistry::typesVisibleTo($this->company, $sales))->toContain('overdue-receivables')
        ->not->toContain('payroll-remittance-due', 'cash-trend-30d');

    Livewire::actingAs($sales)->test('daily-insight', ['company' => $this->company])->assertSee('Three invoices are overdue');
    Livewire::actingAs($banking)->test('daily-insight', ['company' => $this->company])->assertDontSee('Three invoices are overdue');
});
