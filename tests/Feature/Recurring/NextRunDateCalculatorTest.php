<?php

use App\Enums\RecurrenceDayAnchor;
use App\Enums\RecurrenceFrequency;
use App\Models\RecurringDocument;
use App\Services\Recurring\NextRunDateCalculator;
use Carbon\CarbonImmutable;

function scheduleFor(RecurrenceFrequency $frequency, string $startDate, ?int $dayOfMonth = null, ?RecurrenceDayAnchor $anchor = null): RecurringDocument
{
    $doc = new RecurringDocument;
    $doc->frequency = $frequency;
    $doc->start_date = $startDate;
    $doc->day_of_month = $dayOfMonth;
    $doc->day_anchor = $anchor;

    return $doc;
}

function firstDate(RecurringDocument $doc): string
{
    return app(NextRunDateCalculator::class)->first($doc)->toDateString();
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

it('treats a missing anchor as a fixed day of month and starts on the start date', function () {
    $doc = scheduleFor(RecurrenceFrequency::Monthly, '2026-05-24', 1);

    expect($doc->scheduleDayAnchor())->toBe(RecurrenceDayAnchor::DayOfMonth)
        ->and(firstDate($doc))->toBe('2026-05-24')
        ->and(nextDate($doc, '2026-05-24'))->toBe('2026-06-01');
});

it('lands on the calendar last day of each month for a last-day anchor', function () {
    $doc = scheduleFor(RecurrenceFrequency::Monthly, '2026-01-31', anchor: RecurrenceDayAnchor::LastDay);

    expect(nextDate($doc, '2026-01-31'))->toBe('2026-02-28')
        ->and(nextDate($doc, '2026-02-28'))->toBe('2026-03-31')
        ->and(nextDate($doc, '2026-03-31'))->toBe('2026-04-30');

    $leap = scheduleFor(RecurrenceFrequency::Monthly, '2028-01-31', anchor: RecurrenceDayAnchor::LastDay);
    expect(nextDate($leap, '2028-01-31'))->toBe('2028-02-29');
});

it('lands on the last weekday of each period for a last-business-day anchor', function () {
    // Oct 31 2026 is a Saturday → Fri Oct 30; Jan 31 2027 is a Sunday → Fri Jan 29;
    // Apr 30 2027 is a Friday → itself; Jul 31 2027 is a Saturday → Fri Jul 30.
    $quarterly = scheduleFor(RecurrenceFrequency::Quarterly, '2026-10-31', anchor: RecurrenceDayAnchor::LastBusinessDay);

    expect(firstDate($quarterly))->toBe('2026-10-30')
        ->and(nextDate($quarterly, '2026-10-30'))->toBe('2027-01-29')
        ->and(nextDate($quarterly, '2027-01-29'))->toBe('2027-04-30')
        ->and(nextDate($quarterly, '2027-04-30'))->toBe('2027-07-30');

    // Feb 29 2028 (leap day) is a Tuesday; May 31 2026 is a Sunday → Fri May 29.
    $monthly = scheduleFor(RecurrenceFrequency::Monthly, '2028-01-31', anchor: RecurrenceDayAnchor::LastBusinessDay);
    expect(nextDate($monthly, '2028-01-31'))->toBe('2028-02-29')
        ->and(nextDate(scheduleFor(RecurrenceFrequency::Monthly, '2026-04-30', anchor: RecurrenceDayAnchor::LastBusinessDay), '2026-04-30'))->toBe('2026-05-29');
});

it('starts a last-day schedule on the anchor day of the month the start date falls in', function () {
    expect(firstDate(scheduleFor(RecurrenceFrequency::Monthly, '2026-10-05', anchor: RecurrenceDayAnchor::LastDay)))->toBe('2026-10-31')
        ->and(firstDate(scheduleFor(RecurrenceFrequency::Annual, '2026-12-01', anchor: RecurrenceDayAnchor::LastBusinessDay)))->toBe('2026-12-31');
});

it('ignores the day anchor for weekly schedules', function () {
    $doc = scheduleFor(RecurrenceFrequency::Weekly, '2026-01-05', anchor: RecurrenceDayAnchor::LastBusinessDay);

    expect(firstDate($doc))->toBe('2026-01-05')
        ->and(nextDate($doc, '2026-01-05'))->toBe('2026-01-12');
});
