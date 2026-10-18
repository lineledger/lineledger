<?php

use App\Enums\TaxReturnStatus;
use App\Models\Company;
use App\Models\TaxAgency;
use App\Models\TaxReturn;
use App\Services\Tax\VatReturnCalculator;
use Database\Seeders\UkDemoCompanySeeder;

it('builds the UK and Isle of Man demo companies with a filed and a draft VAT return', function () {
    $this->seed(UkDemoCompanySeeder::class);

    $uk = Company::where('slug', 'uk-demo')->firstOrFail();
    $iom = Company::where('slug', 'iom-demo')->firstOrFail();

    expect($uk->currency_code)->toBe('GBP')
        ->and($uk->tax_number)->toBe('GB123456789')
        ->and($iom->timezone)->toBe('Europe/Isle_of_Man')
        ->and(TaxAgency::withoutGlobalScopes()->where('company_id', $iom->id)->value('name'))->toBe('Isle of Man Customs and Excise');

    $returns = TaxReturn::withoutGlobalScopes()->where('company_id', $uk->id)->orderBy('period_start')->get();

    expect($returns)->toHaveCount(2)
        ->and($returns[0]->status)->toBe(TaxReturnStatus::Filed)
        ->and($returns[0]->vat_boxes['box5'])->toBeLessThan(0)
        ->and($returns[1]->status)->toBe(TaxReturnStatus::Draft);

    app()->instance('current_company', $uk);
    expect(app(VatReturnCalculator::class)->forReturn($returns[1])->box5)->toBeGreaterThan(0);
    app()->forgetInstance('current_company');
});

it('rebuilds cleanly when run again', function () {
    $this->seed(UkDemoCompanySeeder::class);
    $this->seed(UkDemoCompanySeeder::class);

    expect(Company::where('slug', 'uk-demo')->count())->toBe(1)
        ->and(Company::where('slug', 'iom-demo')->count())->toBe(1);
});
