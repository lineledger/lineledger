<?php

use App\Enums\CompanyRole;
use App\Enums\TaxAppliesTo;
use App\Models\Company;
use App\Models\TaxCode;
use App\Models\User;
use Livewire\Livewire;

/*
 * Mirror of PurchaseTaxCodeFilterTest: a purchase-only code must never be offered
 * when coding a sale, though a document that already carries one stays editable.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $code = fn (string $code, TaxAppliesTo $appliesTo, bool $active = true) => TaxCode::create([
        'company_id' => $this->company->id,
        'code' => $code,
        'name' => $code,
        'rate_basis_points' => 500,
        'applies_to' => $appliesTo->value,
        'is_active' => $active,
    ]);

    $this->saleOnly = $code('SALEONLY', TaxAppliesTo::SaleOnly);
    $this->purchaseOnly = $code('PURCHONLY', TaxAppliesTo::PurchaseOnly);
    $this->closed = $code('CLOSED', TaxAppliesTo::SaleOnly, active: false);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('offers sale codes only', function (string $component) {
    $codes = Livewire::test($component, ['company' => $this->company])
        ->instance()->taxCodeOptions()->pluck('code')->all();

    expect($codes)->toContain('SALEONLY', 'GST')
        ->not->toContain('PURCHONLY')
        ->not->toContain('CLOSED');
})->with([
    'pages::invoices.form',
    'pages::estimates.form',
    'pages::credit-memos.form',
    'pages::sales-receipts.form',
    'pages::sales-orders.form',
    'pages::invoice-templates.form',
    'pages::customers.index',
]);

it('keeps both codes already on a line', function (string $component) {
    $page = Livewire::test($component, ['company' => $this->company])
        ->set('lines.0.tax_code_id', $this->purchaseOnly->id)
        ->set('lines.0.secondary_tax_code_id', $this->closed->id);

    expect($page->instance()->taxCodeOptions()->pluck('code')->all())
        ->toContain('SALEONLY', 'PURCHONLY', 'CLOSED');
})->with([
    'pages::invoices.form',
    'pages::estimates.form',
    'pages::credit-memos.form',
    'pages::sales-receipts.form',
    'pages::sales-orders.form',
    'pages::invoice-templates.form',
]);

it('keeps the saved default on a customer', function () {
    $page = Livewire::test('pages::customers.index', ['company' => $this->company])
        ->set('f_default_tax_code_id', $this->purchaseOnly->id);

    expect($page->instance()->taxCodeOptions()->pluck('code')->all())->toContain('PURCHONLY');
});

it('keeps the saved default on a vendor', function () {
    $page = Livewire::test('pages::vendors.index', ['company' => $this->company])
        ->set('f_default_tax_code_id', $this->saleOnly->id);

    expect($page->instance()->taxCodeOptions()->pluck('code')->all())->toContain('SALEONLY');
});

it('offers active sale codes on a membership level, keeping its saved default', function () {
    $this->company->forceFill(['features_membership' => true])->save();

    $page = Livewire::test('pages::settings.lists.membership-levels', ['company' => $this->company]);

    expect($page->instance()->taxCodes()->pluck('name')->all())
        ->toContain('SALEONLY')
        ->not->toContain('PURCHONLY')
        ->not->toContain('CLOSED');

    $page->set('f_default_tax_code_id', $this->closed->id);

    expect($page->instance()->taxCodes()->pluck('name')->all())->toContain('CLOSED');
});
