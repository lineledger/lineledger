<?php

namespace App\Providers;

use App\Services\Inventory\FifoCostingService;
use App\Services\Inventory\InventoryCostingFactory;
use App\Services\Inventory\WeightedAverageCostingService;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WeightedAverageCostingService::class);
        $this->app->singleton(FifoCostingService::class);
        $this->app->singleton(InventoryCostingFactory::class);
    }
}
