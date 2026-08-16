<?php

use App\Support\Reporting\ReportDatePresets;
use Carbon\CarbonImmutable;

// Fiscal year starting in August; "today" pinned to 2026-05-22.
$fy = 8;
$today = CarbonImmutable::create(2026, 5, 22);

dataset('presets', [
    'this fiscal year' => ['this_fiscal_year', '2025-08-01', '2026-07-31'],
    'this fiscal year-to-date' => ['this_fiscal_year_to_date', '2025-08-01', '2026-05-22'],
    'last fiscal year' => ['last_fiscal_year', '2024-08-01', '2025-07-31'],
    'last fiscal year-to-date' => ['last_fiscal_year_to_date', '2024-08-01', '2025-05-22'],
    'this month' => ['this_month', '2026-05-01', '2026-05-31'],
    'this month-to-date' => ['this_month_to_date', '2026-05-01', '2026-05-22'],
    'last month' => ['last_month', '2026-04-01', '2026-04-30'],
    'this fiscal quarter' => ['this_fiscal_quarter', '2026-05-01', '2026-07-31'],
    'last fiscal quarter' => ['last_fiscal_quarter', '2026-02-01', '2026-04-30'],
]);

it('resolves fiscal-aware presets', function (string $key, string $start, string $end) use ($fy, $today) {
    $range = ReportDatePresets::resolve($key, $fy, $today);

    expect($range[0]->toDateString())->toBe($start)
        ->and($range[1]->toDateString())->toBe($end);
})->with('presets');

it('returns null for custom so callers keep user-set dates', function () use ($fy, $today) {
    expect(ReportDatePresets::resolve('custom', $fy, $today))->toBeNull();
});
