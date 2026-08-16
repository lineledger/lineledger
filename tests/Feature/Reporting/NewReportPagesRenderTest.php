<?php

use App\Models\Company;
use App\Models\User;
use App\Support\Reporting\ReportCatalog;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('renders each new report page', function (string $component, string $heading) {
    Livewire::test($component, ['company' => $this->company])
        ->assertOk()
        ->assertSee($heading);
})->with([
    'sales by customer' => ['pages::reports.sales-by-customer', 'Sales by Customer'],
    'sales by item' => ['pages::reports.sales-by-item', 'Sales by Item'],
    'sales by rep' => ['pages::reports.sales-by-rep', 'Sales by Rep'],
    'purchases by vendor' => ['pages::reports.purchases-by-vendor', 'Purchases by Vendor'],
    'purchases by item' => ['pages::reports.purchases-by-item', 'Purchases by Item'],
    'open purchase orders' => ['pages::reports.open-purchase-orders', 'Open Purchase Orders'],
    'inventory stock status' => ['pages::reports.inventory-stock-status', 'Inventory Stock Status'],
    'inventory valuation' => ['pages::reports.inventory-valuation', 'Inventory Valuation'],
]);

it('lists the new categories in the catalog, gating inventory and POs by feature flags', function () {
    $this->company->update(['features_inventory' => true, 'features_purchase_orders' => true]);
    $keys = array_keys(ReportCatalog::flatten($this->company->fresh(), $this->user));

    expect($keys)->toContain('reports.sales-by-customer')
        ->and($keys)->toContain('reports.purchases-by-vendor')
        ->and($keys)->toContain('reports.inventory-valuation')
        ->and($keys)->toContain('reports.open-purchase-orders');

    $this->company->update(['features_inventory' => false, 'features_purchase_orders' => false]);
    $gated = array_keys(ReportCatalog::flatten($this->company->fresh(), $this->user));

    expect($gated)->toContain('reports.sales-by-customer')          // always available
        ->and($gated)->not->toContain('reports.inventory-valuation') // gated off
        ->and($gated)->not->toContain('reports.open-purchase-orders'); // gated off
});
