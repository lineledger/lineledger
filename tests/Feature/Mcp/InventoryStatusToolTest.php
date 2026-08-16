<?php

use App\Mcp\Tools\InventoryStatusTool;
use App\Models\Company;
use App\Models\Item;
use Laravel\Mcp\Request;

it('InventoryStatus: lists tracked items with qty on hand and reorder point', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);

    Item::factory()->tracked()->create([
        'company_id' => $company->id,
        'name' => 'Low Widget',
        'sku' => 'W-LOW',
        'qty_on_hand_cached' => 2,
        'reorder_point' => 10,
        'unit_cost_cents_cached' => 500,
    ]);
    Item::factory()->tracked()->create([
        'company_id' => $company->id,
        'name' => 'Healthy Gadget',
        'sku' => 'G-OK',
        'qty_on_hand_cached' => 50,
        'reorder_point' => 5,
        'unit_cost_cents_cached' => 1200,
    ]);
    // Non-tracked item must be excluded.
    Item::factory()->create([
        'company_id' => $company->id,
        'name' => 'Service Only',
        'sku' => 'SVC',
    ]);

    $response = (new InventoryStatusTool)->handle(new Request([]));
    $text = (string) $response->content();

    expect($response->isError())->toBeFalse();
    expect($text)->toContain('Low Widget')
        ->toContain('Healthy Gadget')
        ->toContain('2 on hand')
        ->toContain('reorder at 10')
        ->toContain('REORDER')
        ->toContain('1 of 2 item(s) at or below reorder point')
        ->not->toContain('Service Only');
});

it('InventoryStatus: low_only shows only items at or below reorder point', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);

    Item::factory()->tracked()->create([
        'company_id' => $company->id,
        'name' => 'Restock Me',
        'sku' => 'R-1',
        'qty_on_hand_cached' => 1,
        'reorder_point' => 8,
        'unit_cost_cents_cached' => 300,
    ]);
    Item::factory()->tracked()->create([
        'company_id' => $company->id,
        'name' => 'Plenty Left',
        'sku' => 'P-1',
        'qty_on_hand_cached' => 99,
        'reorder_point' => 4,
        'unit_cost_cents_cached' => 900,
    ]);

    $response = (new InventoryStatusTool)->handle(new Request(['low_only' => true]));
    $text = (string) $response->content();

    expect($response->isError())->toBeFalse();
    expect($text)->toContain('at or below their reorder point')
        ->toContain('Restock Me')
        ->not->toContain('Plenty Left');
});

it('InventoryStatus: reports cleanly when there are no tracked items', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);

    $response = (new InventoryStatusTool)->handle(new Request([]));

    expect($response->isError())->toBeFalse();
    expect((string) $response->content())->toContain('no inventory-tracked items');
});

it('InventoryStatus: refuses inventory status when the key lacks inventory:read', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company, ['sales:read']);

    $response = (new InventoryStatusTool)->handle(new Request([]));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('inventory:read');
});
