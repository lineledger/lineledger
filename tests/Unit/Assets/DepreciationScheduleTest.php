<?php

use App\Models\Asset;
use App\Services\Assets\DepreciationSchedule;
use Tests\TestCase;

// Unit tests are not auto-bound to Laravel's TestCase by Pest.php (only Feature is);
// we opt in because the Asset date casts resolve through the Date facade, which
// needs the application container. No database is touched — assets stay unsaved.
uses(TestCase::class);

function scheduleAsset(array $attributes = []): Asset
{
    return new Asset(array_merge([
        'cost_cents' => 100000,
        'salvage_value_cents' => 0,
        'useful_life_months' => 36,
        'in_service_date' => '2026-01-15',
    ], $attributes));
}

it('splits the base with intdiv and gives the final month the exact remainder', function () {
    $rows = DepreciationSchedule::for(scheduleAsset());

    expect($rows)->toHaveCount(36);

    // intdiv(100000, 36) = 2777; 35 × 2777 = 97195; final month = 2805.
    foreach (array_slice($rows, 0, 35) as $row) {
        expect($row['amount_cents'])->toBe(2777);
    }

    expect($rows[35]['amount_cents'])->toBe(2805)
        ->and(array_sum(array_column($rows, 'amount_cents')))->toBe(100000)
        ->and($rows[35]['cumulative_cents'])->toBe(100000)
        ->and($rows[0]['cumulative_cents'])->toBe(2777)
        ->and($rows[1]['cumulative_cents'])->toBe(5554);
});

it('starts month 1 in the calendar month containing the in-service date', function () {
    $rows = DepreciationSchedule::for(scheduleAsset(['in_service_date' => '2026-01-15']));

    expect($rows[0]['period']->toDateString())->toBe('2026-01-01')
        ->and($rows[1]['period']->toDateString())->toBe('2026-02-01')
        ->and($rows[35]['period']->toDateString())->toBe('2028-12-01');
});

it('depreciates only down to the salvage value', function () {
    $rows = DepreciationSchedule::for(scheduleAsset([
        'cost_cents' => 120000,
        'salvage_value_cents' => 20000,
        'useful_life_months' => 10,
    ]));

    expect($rows)->toHaveCount(10)
        ->and(array_sum(array_column($rows, 'amount_cents')))->toBe(100000)
        ->and($rows[0]['amount_cents'])->toBe(10000);
});

it('is empty when the math is ineligible', function () {
    expect(DepreciationSchedule::for(scheduleAsset(['in_service_date' => null])))->toBe([])
        ->and(DepreciationSchedule::for(scheduleAsset(['useful_life_months' => 0])))->toBe([])
        ->and(DepreciationSchedule::for(scheduleAsset(['useful_life_months' => null])))->toBe([])
        ->and(DepreciationSchedule::for(scheduleAsset(['salvage_value_cents' => 100000])))->toBe([])
        ->and(DepreciationSchedule::for(scheduleAsset(['salvage_value_cents' => 150000])))->toBe([]);
});
