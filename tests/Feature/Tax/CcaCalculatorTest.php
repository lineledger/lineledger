<?php

use App\Enums\AssetStatus;
use App\Enums\CcaClass;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\CcaPool;
use App\Models\Company;
use App\Services\Tax\CcaCalculator;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function ccaRow(array $schedule, string $class): ?array
{
    return collect($schedule['rows'])->firstWhere('class', $class);
}

function addAsset(Company $company, CcaClass $class, int $costCents, string $inService): void
{
    $category = AssetCategory::factory()->for($company)->create(['cca_class' => $class->value]);
    Asset::factory()->for($company)->create([
        'asset_category_id' => $category->id,
        'cost_cents' => $costCents,
        'in_service_date' => $inService,
        'status' => AssetStatus::InService->value,
    ]);
}

it('applies the half-year rule to current-year additions', function () {
    addAsset($this->company, CcaClass::Class8, 1_000_00, '2026-05-01'); // $1,000 Class 8 (20%)

    $row = ccaRow(app(CcaCalculator::class)->schedule($this->company, 2026), '8');

    // base 1000, half-year base 500, CCA = 500 * 20% = 100; closing = 1000 - 100 = 900.
    expect($row['cca_cents'])->toBe(100_00)
        ->and($row['closing_cents'])->toBe(900_00);
});

it('claims the full rate on opening UCC with no half-year reduction', function () {
    CcaPool::create(['company_id' => $this->company->id, 'tax_year' => 2026, 'cca_class' => CcaClass::Class8->value, 'opening_ucc_cents' => 1_000_00]);

    $row = ccaRow(app(CcaCalculator::class)->schedule($this->company, 2026), '8');

    // No additions → CCA = 1000 * 20% = 200; closing = 800.
    expect($row['cca_cents'])->toBe(200_00)
        ->and($row['closing_cents'])->toBe(800_00);
});

it('combines opening UCC and additions with the half-year rule', function () {
    CcaPool::create(['company_id' => $this->company->id, 'tax_year' => 2026, 'cca_class' => CcaClass::Class8->value, 'opening_ucc_cents' => 500_00]);
    addAsset($this->company, CcaClass::Class8, 1_000_00, '2026-06-01');

    $row = ccaRow(app(CcaCalculator::class)->schedule($this->company, 2026), '8');

    // base 1500, half-year base 1500-500 = 1000, CCA = 1000 * 20% = 200; closing 1300.
    expect($row['opening_cents'])->toBe(500_00)
        ->and($row['additions_cents'])->toBe(1_000_00)
        ->and($row['cca_cents'])->toBe(200_00)
        ->and($row['closing_cents'])->toBe(1_300_00);
});

it('uses each class rate and ignores assets placed in service in other years', function () {
    addAsset($this->company, CcaClass::Class50, 1_000_00, '2026-03-01'); // 55%
    addAsset($this->company, CcaClass::Class50, 9_999_00, '2025-03-01'); // prior year, excluded

    $schedule = app(CcaCalculator::class)->schedule($this->company, 2026);
    $row = ccaRow($schedule, '50');

    // Only the 2026 addition: base 1000, half 500, CCA = 500 * 55% = 275.
    expect($row['additions_cents'])->toBe(1_000_00)
        ->and($row['cca_cents'])->toBe(275_00)
        ->and($schedule['total_cca_cents'])->toBe(275_00);
});
