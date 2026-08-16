<?php

use App\Support\Reporting\ComparisonPeriod;
use Carbon\CarbonImmutable;

it('returns null when comparison is off', function () {
    expect(ComparisonPeriod::forRange(
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
        ComparisonPeriod::Off,
        'this_month',
    ))->toBeNull();

    expect(ComparisonPeriod::forAsOf(
        CarbonImmutable::parse('2026-04-30'),
        ComparisonPeriod::Off,
        CarbonImmutable::parse('2026-04-01'),
    ))->toBeNull();
});

it('shifts a range back one calendar year for prior year', function () {
    [$start, $end] = ComparisonPeriod::forRange(
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
        ComparisonPeriod::PriorYear,
        'this_month',
    );

    expect($start->toDateString())->toBe('2025-05-01')
        ->and($end->toDateString())->toBe('2025-05-31');
});

it('resolves the immediately preceding month for prior period', function () {
    [$start, $end] = ComparisonPeriod::forRange(
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
        ComparisonPeriod::PriorPeriod,
        'this_month',
    );

    expect($start->toDateString())->toBe('2026-04-01')
        ->and($end->toDateString())->toBe('2026-04-30');
});

it('resolves the immediately preceding quarter for prior period', function () {
    [$start, $end] = ComparisonPeriod::forRange(
        CarbonImmutable::parse('2026-04-01'),
        CarbonImmutable::parse('2026-06-30'),
        ComparisonPeriod::PriorPeriod,
        'this_fiscal_quarter',
    );

    expect($start->toDateString())->toBe('2026-01-01')
        ->and($end->toDateString())->toBe('2026-03-31');
});

it('resolves the immediately preceding year for prior period', function () {
    [$start, $end] = ComparisonPeriod::forRange(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-12-31'),
        ComparisonPeriod::PriorPeriod,
        'this_fiscal_year',
    );

    expect($start->toDateString())->toBe('2025-01-01')
        ->and($end->toDateString())->toBe('2025-12-31');
});

it('mirrors an equal-length preceding block for a custom range prior period', function () {
    // 2026-05-10 .. 2026-05-19 is a 10-day window; the prior block ends the day
    // before it starts and spans the same number of days.
    [$start, $end] = ComparisonPeriod::forRange(
        CarbonImmutable::parse('2026-05-10'),
        CarbonImmutable::parse('2026-05-19'),
        ComparisonPeriod::PriorPeriod,
        'custom',
    );

    expect($end->toDateString())->toBe('2026-05-09')
        ->and($start->toDateString())->toBe('2026-04-30');
});

it('resolves the preceding period end for an as-of prior period', function () {
    $prior = ComparisonPeriod::forAsOf(
        CarbonImmutable::parse('2026-04-30'),
        ComparisonPeriod::PriorPeriod,
        CarbonImmutable::parse('2026-04-01'),
    );

    expect($prior->toDateString())->toBe('2026-03-31');
});

it('falls back to one month earlier for a custom as-of prior period', function () {
    $prior = ComparisonPeriod::forAsOf(
        CarbonImmutable::parse('2026-04-15'),
        ComparisonPeriod::PriorPeriod,
        null,
    );

    expect($prior->toDateString())->toBe('2026-03-15');
});

it('subtracts a year for an as-of prior year', function () {
    $prior = ComparisonPeriod::forAsOf(
        CarbonImmutable::parse('2026-04-30'),
        ComparisonPeriod::PriorYear,
        CarbonImmutable::parse('2026-04-01'),
    );

    expect($prior->toDateString())->toBe('2025-04-30');
});
