<?php

use App\Enums\Country;
use App\Models\Company;

/**
 * BC and Yukon companies were defaulted to America/Los_Angeles, which falls
 * back to UTC−8 each winter while both stay on UTC−7. The migration moves
 * that old default to their own zones and leaves any other choice alone.
 */
function runBcYukonTimezoneMigration(): void
{
    (require database_path('migrations/2026_10_05_000002_move_bc_and_yukon_companies_off_us_pacific_time.php'))->up();
}

test('BC and Yukon companies on US Pacific time move to their own zones', function () {
    $bc = Company::factory()->forCountry(Country::Canada, 'BC')->create(['timezone' => 'America/Los_Angeles']);
    $yukon = Company::factory()->forCountry(Country::Canada, 'YT')->create(['timezone' => 'America/Los_Angeles']);
    $deletedBc = Company::factory()->forCountry(Country::Canada, 'BC')->create(['timezone' => 'America/Los_Angeles']);
    $deletedBc->delete();

    runBcYukonTimezoneMigration();

    expect($bc->fresh()->timezone)->toBe('America/Vancouver');
    expect($yukon->fresh()->timezone)->toBe('America/Whitehorse');
    // A restored company must come back on BC time too.
    expect(Company::withTrashed()->find($deletedBc->id)->timezone)->toBe('America/Vancouver');
});

test('a zone an owner chose, or a US Pacific company, is left alone', function () {
    $bcTokyo = Company::factory()->forCountry(Country::Canada, 'BC')->create(['timezone' => 'Asia/Tokyo']);
    $bcUtc = Company::factory()->forCountry(Country::Canada, 'BC')->create(['timezone' => 'UTC']);
    $alberta = Company::factory()->forCountry(Country::Canada, 'AB')->create(['timezone' => 'America/Los_Angeles']);
    $washington = Company::factory()->forCountry(Country::UnitedStates, 'WA')->create(['timezone' => 'America/Los_Angeles']);

    runBcYukonTimezoneMigration();

    expect($bcTokyo->fresh()->timezone)->toBe('Asia/Tokyo');
    expect($bcUtc->fresh()->timezone)->toBe('UTC');
    expect($alberta->fresh()->timezone)->toBe('America/Los_Angeles');
    expect($washington->fresh()->timezone)->toBe('America/Los_Angeles');
});

test('BC time is a choice in the settings picker', function () {
    expect(Company::timezoneOptions())
        ->toContain('America/Vancouver')
        ->toContain('America/Whitehorse');
});
