<?php

use App\Enums\CompanyRole;
use App\Enums\TaxAppliesTo;
use App\Models\Company;
use App\Models\TaxCode;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    // A sale-only code must never be offered when coding a purchase; a
    // purchase-only code must be. (Seeded GST is applies_to = both.)
    $this->saleOnly = TaxCode::create([
        'company_id' => $this->company->id,
        'code' => 'SALEONLY',
        'name' => 'Sale-only tax',
        'rate_basis_points' => 800,
        'applies_to' => TaxAppliesTo::SaleOnly->value,
    ]);

    $this->purchaseOnly = TaxCode::create([
        'company_id' => $this->company->id,
        'code' => 'PURCHONLY',
        'name' => 'Purchase-only tax',
        'rate_basis_points' => 500,
        'applies_to' => TaxAppliesTo::PurchaseOnly->value,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('excludes sale-only codes from the forPurchases scope but keeps purchase-only and both', function () {
    $codes = TaxCode::query()->forPurchases()->pluck('code')->all();

    expect($codes)->toContain('PURCHONLY')
        ->and($codes)->toContain('GST') // seeded default, applies_to = both
        ->and($codes)->not->toContain('SALEONLY');
});

it('keeps sale-only codes available to the forSales scope', function () {
    $codes = TaxCode::query()->forSales()->pluck('code')->all();

    expect($codes)->toContain('SALEONLY')
        ->and($codes)->toContain('GST')
        ->and($codes)->not->toContain('PURCHONLY');
});

it('hides sale-only codes from every purchase form tax picker', function () {
    $components = [
        'pages::bills.form',
        'pages::cheques.form',
        'pages::vendor-credits.form',
        'pages::purchase-orders.form',
        'pages::expenses.form',
    ];

    foreach ($components as $component) {
        $codes = Livewire::test($component, ['company' => $this->company])
            ->instance()
            ->taxCodeOptions()
            ->pluck('code')
            ->all();

        expect(in_array('PURCHONLY', $codes, true))
            ->toBeTrue("purchase-only code missing from {$component}");
        expect(in_array('SALEONLY', $codes, true))
            ->toBeFalse("sale-only code leaked into {$component}");
    }
});

it('still offers a sale-only code already saved on a line so existing documents stay editable', function () {
    $codes = Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('lines', [[
            'account_id' => null,
            'description' => 'Legacy line',
            'amount' => '10.00',
            'tax_code_id' => $this->saleOnly->id,
            'tax_override' => '',
            'class_id' => null,
            'location_id' => null,
            'auto_tax_cents' => 0,
            'tax_cents' => 0,
            'total' => 0,
        ]])
        ->instance()
        ->taxCodeOptions()
        ->pluck('code')
        ->all();

    expect($codes)->toContain('SALEONLY');
});
