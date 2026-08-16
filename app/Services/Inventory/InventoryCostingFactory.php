<?php

namespace App\Services\Inventory;

use App\Enums\CostingMethod;
use App\Models\Company;

class InventoryCostingFactory
{
    public function for(Company $company): InventoryCostingService
    {
        return match ($company->costing_method) {
            CostingMethod::Fifo => app(FifoCostingService::class),
            default => app(WeightedAverageCostingService::class),
        };
    }
}
