<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Services\Inventory\MovementContext;
use App\Services\Inventory\WeightedAverageCostingService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create(['costing_method' => 'weighted_average']);
    app()->instance('current_company', $this->company);

    $this->item = Item::factory()->tracked()->create([
        'inventory_asset_account_id' => Account::query()->where('subtype', AccountSubtype::Inventory->value)->first()->id,
        'cogs_account_id' => Account::query()->where('subtype', AccountSubtype::CostOfGoodsSold->value)->first()->id,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('canChangeCostingMethod is true before any stock movement', function () {
    expect($this->company->canChangeCostingMethod())->toBeTrue();
});

it('canChangeCostingMethod is false once a stock movement exists', function () {
    app(WeightedAverageCostingService::class)->recordReceipt(
        $this->item,
        '10',
        500,
        MovementContext::for(CarbonImmutable::parse('2026-05-20')),
    );

    expect($this->company->fresh()->canChangeCostingMethod())->toBeFalse();
});
