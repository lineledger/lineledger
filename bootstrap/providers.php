<?php

use App\Providers\AppServiceProvider;
use App\Providers\BankingServiceProvider;
use App\Providers\ClassificationServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\InboxServiceProvider;
use App\Providers\InsightServiceProvider;
use App\Providers\InventoryServiceProvider;

return [
    AppServiceProvider::class,
    BankingServiceProvider::class,
    ClassificationServiceProvider::class,
    FortifyServiceProvider::class,
    InboxServiceProvider::class,
    InsightServiceProvider::class,
    InventoryServiceProvider::class,
];
