<?php

use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\InvoiceSetting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('renders the invoice settings page', function () {
    $this->get(route('settings.invoices', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('Invoices');
});

it('persists invoice settings and the company tax number', function () {
    Livewire::test('pages::settings.invoices', ['company' => $this->company])
        ->set('showQtyColumn', false)
        ->set('showTaxNumber', true)
        ->set('taxNumber', '123456789 RT0001')
        ->set('footerMessage', 'Thank you for your business')
        ->call('save')
        ->assertHasNoErrors();

    $settings = InvoiceSetting::query()->where('company_id', $this->company->id)->first();

    expect($settings)->not->toBeNull()
        ->and($settings->show_qty_column)->toBeFalse()
        ->and($settings->show_tax_number)->toBeTrue()
        ->and($settings->footer_message)->toBe('Thank you for your business')
        ->and($this->company->fresh()->tax_number)->toBe('123456789 RT0001');
});

it('persists freeform payment instructions for the portal', function () {
    Livewire::test('pages::settings.invoices', ['company' => $this->company])
        ->set('paymentInstructions', "Send an e-Transfer to billing@example.com.\nOr call (555) 123-4567.")
        ->call('save')
        ->assertHasNoErrors();

    expect(InvoiceSetting::query()->where('company_id', $this->company->id)->value('payment_instructions'))
        ->toBe("Send an e-Transfer to billing@example.com.\nOr call (555) 123-4567.");
});

it('loads and persists the service date column toggle', function () {
    InvoiceSetting::updateOrCreate(
        ['company_id' => $this->company->id],
        [...InvoiceSetting::defaults(), 'show_service_date_column' => false],
    );

    Livewire::test('pages::settings.invoices', ['company' => $this->company])
        ->assertSet('showServiceDateColumn', false)
        ->set('showServiceDateColumn', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(InvoiceSetting::query()->where('company_id', $this->company->id)->first()->show_service_date_column)
        ->toBeTrue();
});

it('loads and persists the hide zero-quantity lines toggle', function () {
    InvoiceSetting::updateOrCreate(
        ['company_id' => $this->company->id],
        [...InvoiceSetting::defaults(), 'hide_zero_qty_lines' => true],
    );

    Livewire::test('pages::settings.invoices', ['company' => $this->company])
        ->assertSet('hideZeroQtyLines', true)
        ->set('hideZeroQtyLines', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(InvoiceSetting::query()->where('company_id', $this->company->id)->first()->hide_zero_qty_lines)
        ->toBeFalse();
});

it('persists the granular document-header field toggles', function () {
    Livewire::test('pages::settings.invoices', ['company' => $this->company])
        ->assertSet('showCompanyName', true)
        ->assertSet('showCompanyEmail', false)
        ->set('showCompanyName', false)
        ->set('showLegalName', true)
        ->set('showCompanyEmail', true)
        ->set('showCompanyWebsite', true)
        ->call('save')
        ->assertHasNoErrors();

    $settings = InvoiceSetting::query()->where('company_id', $this->company->id)->first();

    expect($settings)->not->toBeNull()
        ->and($settings->show_company_name)->toBeFalse()
        ->and($settings->show_legal_name)->toBeTrue()
        ->and($settings->show_company_email)->toBeTrue()
        ->and($settings->show_company_website)->toBeTrue()
        ->and($settings->show_company_address)->toBeTrue();
});

it('blanks the tax number when cleared', function () {
    $this->company->update(['tax_number' => '999']);

    Livewire::test('pages::settings.invoices', ['company' => $this->company])
        ->set('taxNumber', '   ')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->company->fresh()->tax_number)->toBeNull();
});

it('persists the default sales account', function () {
    $income = Account::query()
        ->selectableForItemAccount()
        ->where('type', AccountType::Income->value)
        ->where('is_active', true)
        ->orderBy('code')
        ->value('id');

    Livewire::test('pages::settings.invoices', ['company' => $this->company])
        ->set('defaultSalesAccountId', $income)
        ->call('save')
        ->assertHasNoErrors();

    expect(InvoiceSetting::query()->where('company_id', $this->company->id)->value('default_sales_account_id'))
        ->toBe($income);
});

it('forbids a member without update permission from saving', function () {
    $member = User::factory()->create();
    $this->company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);
    $this->actingAs($member);

    Livewire::test('pages::settings.invoices', ['company' => $this->company])
        ->set('taxNumber', 'hijack')
        ->call('save')
        ->assertForbidden();

    expect($this->company->fresh()->tax_number)->not->toBe('hijack');
});
