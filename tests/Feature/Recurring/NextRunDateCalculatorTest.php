<?php

use App\Enums\RecurrenceFrequency;
use App\Models\RecurringDocument;
use App\Services\Recurring\NextRunDateCalculator;
use Carbon\CarbonImmutable;

function scheduleFor(RecurrenceFrequency $frequency, string $startDate, ?int $dayOfMonth = null): RecurringDocument
{
    $doc = new RecurringDocument;
    $doc->frequency = $frequency;
    $doc->start_date = $startDate;
    $doc->day_of_month = $dayOfMonth;

    return $doc;
}

function nextDate(RecurringDocument $doc, string $from): string
{
    return app(NextRunDateCalculator::class)
        ->next($doc, CarbonImmutable::parse($from))
        ->toDateString();
}

it('advances weekly schedules by seven days', function () {
    $doc = scheduleFor(RecurrenceFrequency::Weekly, '2026-01-05');

    expect(nextDate($doc, '2026-01-05'))->toBe('2026-01-12')
        ->and(nextDate($doc, '2026-01-12'))->toBe('2026-01-19');
});

it('advances monthly schedules keeping the day-of-month anchor', function () {
    $doc = scheduleFor(RecurrenceFrequency::Monthly, '2026-01-15', 15);

    expect(nextDate($doc, '2026-01-15'))->toBe('2026-02-15')
        ->and(nextDate($doc, '2026-02-15'))->toBe('2026-03-15');
});

it('clamps a day-31 anchor to short months then re-expands', function () {
    $doc = scheduleFor(RecurrenceFrequency::Monthly, '2026-01-31', 31);

    // Feb clamps to 28 (2026 is not a leap year), but March re-anchors to 31
    // rather than collapsing permanently to the 28th.
    expect(nextDate($doc, '2026-01-31'))->toBe('2026-02-28')
        ->and(nextDate($doc, '2026-02-28'))->toBe('2026-03-31')
        ->and(nextDate($doc, '2026-03-31'))->toBe('2026-04-30');
});

it('clamps a day-29 anchor on a leap year February', function () {
    $doc = scheduleFor(RecurrenceFrequency::Monthly, '2024-01-29', 29);

    expect(nextDate($doc, '2024-01-29'))->toBe('2024-02-29'); // 2024 is a leap year
});

it('advances quarterly, semi-annual, and annual schedules', function () {
    expect(nextDate(scheduleFor(RecurrenceFrequency::Quarterly, '2026-01-10', 10), '2026-01-10'))->toBe('2026-04-10')
        ->and(nextDate(scheduleFor(RecurrenceFrequency::SemiAnnual, '2026-01-10', 10), '2026-01-10'))->toBe('2026-07-10')
        ->and(nextDate(scheduleFor(RecurrenceFrequency::Annual, '2026-01-10', 10), '2026-01-10'))->toBe('2027-01-10');
});

it('falls back to the start-date day when no day-of-month is set', function () {
    $doc = scheduleFor(RecurrenceFrequency::Monthly, '2026-01-08');

    expect(nextDate($doc, '2026-01-08'))->toBe('2026-02-08');
});
