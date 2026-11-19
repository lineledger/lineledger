<?php

use App\Enums\DeadlineFrequency;
use App\Enums\DueDayRule;
use App\Enums\RecurrenceEndType;
use App\Enums\RemittanceFrequency;
use App\Enums\WeekendAdjustment;
use App\Services\Deadlines\DeadlineRule;
use App\Services\Deadlines\DeadlineScheduleCalculator;
use App\Services\Deadlines\ScheduledOccurrence;
use App\Services\Payroll\RemittancePeriodResolver;
use Carbon\CarbonImmutable;

function deadlineDates(array $occurrences): array
{
    return array_map(fn (ScheduledOccurrence $o): string => $o->dueDate->toDateString(), $occurrences);
}

function deadlineDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

it('puts a quarterly GST/HST return on the last day of the month after each fiscal quarter', function () {
    $rule = new DeadlineRule(DeadlineFrequency::Quarterly, periodAnchor: deadlineDay('2026-08-01'),
        dueMonthsAfter: 1, dueDayRule: DueDayRule::LastDay);

    $occurrences = (new DeadlineScheduleCalculator)->upcoming($rule, deadlineDay('2026-08-01'), 4);

    expect(deadlineDates($occurrences))->toBe(['2026-11-30', '2027-02-28', '2027-05-31', '2027-08-31'])
        ->and($occurrences[0]->periodStart->toDateString())->toBe('2026-08-01')
        ->and($occurrences[0]->periodEnd->toDateString())->toBe('2026-10-31')
        ->and($occurrences[0]->key)->toBe('2026-08-01');
});

it('puts monthly BC PST on the last day of the following month', function () {
    $rule = new DeadlineRule(DeadlineFrequency::Monthly, periodAnchor: deadlineDay('2026-08-01'),
        dueMonthsAfter: 1, dueDayRule: DueDayRule::LastDay);

    expect(deadlineDates((new DeadlineScheduleCalculator)->upcoming($rule, deadlineDay('2026-01-01'), 3)))
        ->toBe(['2026-09-30', '2026-10-31', '2026-11-30']);
});

it('handles mid-month quarterly periods (Jun 16 – Sep 15, due Sep 30)', function () {
    $rule = new DeadlineRule(DeadlineFrequency::Quarterly, periodAnchor: deadlineDay('2026-06-16'),
        dueMonthsAfter: 0, dueDayRule: DueDayRule::LastDay);

    $occurrences = (new DeadlineScheduleCalculator)->upcoming($rule, deadlineDay('2026-01-01'), 4);

    expect($occurrences[0]->periodEnd->toDateString())->toBe('2026-09-15')
        ->and(deadlineDates($occurrences))->toBe(['2026-09-30', '2026-12-31', '2027-03-31', '2027-06-30']);
});

it('puts WorkSafeBC quarterly reports on the 20th after the quarter', function () {
    $rule = new DeadlineRule(DeadlineFrequency::Quarterly, periodAnchor: deadlineDay('2026-01-01'),
        dueMonthsAfter: 1, dueDayRule: DueDayRule::DayOfMonth, dueDayOfMonth: 20);

    expect(deadlineDates((new DeadlineScheduleCalculator)->upcoming($rule, deadlineDay('2026-01-01'), 4)))
        ->toBe(['2026-04-20', '2026-07-20', '2026-10-20', '2027-01-20']);
});

it('puts the T2 return six months after a July 31 year end', function () {
    $rule = new DeadlineRule(DeadlineFrequency::Annual, periodAnchor: deadlineDay('2025-08-01'),
        dueMonthsAfter: 6, dueDayRule: DueDayRule::LastDay);

    expect(deadlineDates((new DeadlineScheduleCalculator)->upcoming($rule, deadlineDay('2026-01-01'), 2)))
        ->toBe(['2027-01-31', '2028-01-31']);
});

it('puts T4 slips on the last day of February, leap years included', function () {
    $rule = new DeadlineRule(DeadlineFrequency::Annual, periodAnchor: deadlineDay('2026-01-01'),
        dueMonthsAfter: 2, dueDayRule: DueDayRule::LastDay);

    expect(deadlineDates((new DeadlineScheduleCalculator)->upcoming($rule, deadlineDay('2026-01-01'), 3)))
        ->toBe(['2027-02-28', '2028-02-29', '2029-02-28']);
});

it('puts UK VAT one month and seven days after the period ends', function () {
    $rule = new DeadlineRule(DeadlineFrequency::Quarterly, periodAnchor: deadlineDay('2026-04-01'),
        dueMonthsAfter: 1, dueDayRule: DueDayRule::LastDay, dueDaysAfter: 7);

    expect(deadlineDates((new DeadlineScheduleCalculator)->upcoming($rule, deadlineDay('2026-01-01'), 4)))
        ->toBe(['2026-08-07', '2026-11-07', '2027-02-07', '2027-05-07']);
});

it('puts UK Corporation Tax nine months and one day after the year end', function () {
    $rule = new DeadlineRule(DeadlineFrequency::Annual, periodAnchor: deadlineDay('2026-04-01'),
        dueMonthsAfter: 9, dueDayRule: DueDayRule::SameDay, dueDaysAfter: 1);

    expect(deadlineDates((new DeadlineScheduleCalculator)->upcoming($rule, deadlineDay('2026-01-01'), 2)))
        ->toBe(['2028-01-01', '2029-01-01']);
});

it('lists fixed dates each year (BC EHT instalments)', function () {
    $rule = new DeadlineRule(DeadlineFrequency::AnnualDates, periodAnchor: deadlineDay('2026-07-01'),
        annualDates: DeadlineRule::normalizeAnnualDates([['month' => 12, 'day' => 15], ['month' => 6, 'day' => 15], ['month' => 9, 'day' => 15]]));

    $occurrences = (new DeadlineScheduleCalculator)->upcoming($rule, deadlineDay('2026-01-01'), 4);

    expect(deadlineDates($occurrences))->toBe(['2026-09-15', '2026-12-15', '2027-06-15', '2027-09-15'])
        ->and($occurrences[0]->periodStart)->toBeNull()
        ->and($occurrences[0]->key)->toBe('2026-09-15');
});

it('supports "last" as an annual date day', function () {
    $rule = new DeadlineRule(DeadlineFrequency::AnnualDates, periodAnchor: deadlineDay('2027-01-01'),
        annualDates: DeadlineRule::normalizeAnnualDates([['month' => 2, 'day' => 'last']]));

    expect(deadlineDates((new DeadlineScheduleCalculator)->upcoming($rule, deadlineDay('2027-01-01'), 2)))
        ->toBe(['2027-02-28', '2028-02-29']);
});

it('clamps day 31 without drifting the rest of the series', function () {
    $rule = new DeadlineRule(DeadlineFrequency::Monthly, periodAnchor: deadlineDay('2026-01-01'),
        dueMonthsAfter: 1, dueDayRule: DueDayRule::DayOfMonth, dueDayOfMonth: 31);

    expect(deadlineDates((new DeadlineScheduleCalculator)->upcoming($rule, deadlineDay('2026-01-01'), 3)))
        ->toBe(['2026-02-28', '2026-03-31', '2026-04-30']);
});

it('runs every two weeks', function () {
    $rule = new DeadlineRule(DeadlineFrequency::Weekly, interval: 2, periodAnchor: deadlineDay('2026-11-02'));

    $occurrences = (new DeadlineScheduleCalculator)->upcoming($rule, deadlineDay('2026-11-01'), 3);

    expect($occurrences[0]->periodEnd->toDateString())->toBe('2026-11-15')
        ->and(deadlineDates($occurrences))->toBe(['2026-11-15', '2026-11-29', '2026-12-13']);
});

it('moves a weekend due date either way, and keeps it when asked', function (WeekendAdjustment $adjustment, string $expected) {
    // Oct 31 2026 is a Saturday.
    $rule = new DeadlineRule(DeadlineFrequency::Once, dueDate: deadlineDay('2026-10-31'), weekendAdjustment: $adjustment);

    $occurrence = (new DeadlineScheduleCalculator)->upcoming($rule, deadlineDay('2026-01-01'), 1)[0];

    expect($occurrence->dueDate->toDateString())->toBe($expected)
        ->and($occurrence->unadjustedDueDate->toDateString())->toBe('2026-10-31');
})->with([
    'keep' => [WeekendAdjustment::Keep, '2026-10-31'],
    'previous' => [WeekendAdjustment::PreviousBusinessDay, '2026-10-30'],
    'next' => [WeekendAdjustment::NextBusinessDay, '2026-11-02'],
]);

it('moves a Sunday to the Friday before or the Monday after', function () {
    $calculator = new DeadlineScheduleCalculator;
    $sunday = deadlineDay('2026-11-01');

    expect($calculator->adjustForWeekend($sunday, WeekendAdjustment::PreviousBusinessDay)->toDateString())->toBe('2026-10-30')
        ->and($calculator->adjustForWeekend($sunday, WeekendAdjustment::NextBusinessDay)->toDateString())->toBe('2026-11-02');
});

it('stops after a number of occurrences', function () {
    $rule = new DeadlineRule(DeadlineFrequency::Monthly, periodAnchor: deadlineDay('2026-01-01'),
        dueMonthsAfter: 1, dueDayRule: DueDayRule::DayOfMonth, dueDayOfMonth: 15,
        endType: RecurrenceEndType::AfterOccurrences, maxOccurrences: 3);

    $calculator = new DeadlineScheduleCalculator;

    expect(deadlineDates($calculator->upcoming($rule, deadlineDay('2026-01-01'), 10)))->toBe(['2026-02-15', '2026-03-15', '2026-04-15'])
        // The count runs from the first period, not from the window.
        ->and(deadlineDates($calculator->upcoming($rule, deadlineDay('2026-03-01'), 10)))->toBe(['2026-03-15', '2026-04-15']);
});

it('stops at an end date', function () {
    $rule = new DeadlineRule(DeadlineFrequency::Monthly, periodAnchor: deadlineDay('2026-01-01'),
        dueMonthsAfter: 1, dueDayRule: DueDayRule::DayOfMonth, dueDayOfMonth: 15,
        endType: RecurrenceEndType::OnDate, endDate: deadlineDay('2026-04-15'));

    expect(deadlineDates((new DeadlineScheduleCalculator)->upcoming($rule, deadlineDay('2026-01-01'), 10)))
        ->toBe(['2026-02-15', '2026-03-15', '2026-04-15']);
});

it('returns the occurrences due inside a window', function () {
    $rule = new DeadlineRule(DeadlineFrequency::Monthly, periodAnchor: deadlineDay('2026-01-01'),
        dueMonthsAfter: 1, dueDayRule: DueDayRule::DayOfMonth, dueDayOfMonth: 15);

    expect(deadlineDates((new DeadlineScheduleCalculator)->between($rule, deadlineDay('2026-05-01'), deadlineDay('2026-07-15'))))
        ->toBe(['2026-05-15', '2026-06-15', '2026-07-15']);
});

it('produces nothing without an anchor or a date', function () {
    $calculator = new DeadlineScheduleCalculator;

    expect($calculator->upcoming(new DeadlineRule(DeadlineFrequency::Monthly), deadlineDay('2026-01-01'), 3))->toBe([])
        ->and($calculator->upcoming(new DeadlineRule(DeadlineFrequency::Once), deadlineDay('2026-01-01'), 3))->toBe([])
        ->and($calculator->upcoming(new DeadlineRule(DeadlineFrequency::AnnualDates, periodAnchor: deadlineDay('2026-01-01')), deadlineDay('2026-01-01'), 3))->toBe([]);
});

it('agrees with the payroll remittance resolver on CRA source-deduction due dates', function () {
    $resolver = new RemittancePeriodResolver;
    $calculator = new DeadlineScheduleCalculator;

    $monthly = new DeadlineRule(DeadlineFrequency::Monthly, periodAnchor: deadlineDay('2026-01-01'),
        dueMonthsAfter: 1, dueDayRule: DueDayRule::DayOfMonth, dueDayOfMonth: 15);
    $quarterly = new DeadlineRule(DeadlineFrequency::Quarterly, periodAnchor: deadlineDay('2026-01-01'),
        dueMonthsAfter: 1, dueDayRule: DueDayRule::DayOfMonth, dueDayOfMonth: 15);
    $accelFirstHalf = new DeadlineRule(DeadlineFrequency::Monthly, periodAnchor: deadlineDay('2026-01-01'),
        dueMonthsAfter: 0, dueDayRule: DueDayRule::DayOfMonth, dueDayOfMonth: 25);
    $accelSecondHalf = new DeadlineRule(DeadlineFrequency::Monthly, periodAnchor: deadlineDay('2026-01-01'),
        dueMonthsAfter: 1, dueDayRule: DueDayRule::DayOfMonth, dueDayOfMonth: 10);

    foreach ($calculator->upcoming($monthly, deadlineDay('2026-01-01'), 12) as $occurrence) {
        expect($occurrence->dueDate->toDateString())
            ->toBe($resolver->dueDate(RemittanceFrequency::Monthly, $occurrence->periodEnd)->toDateString());
    }

    foreach ($calculator->upcoming($quarterly, deadlineDay('2026-01-01'), 4) as $occurrence) {
        expect($occurrence->dueDate->toDateString())
            ->toBe($resolver->dueDate(RemittanceFrequency::Quarterly, $occurrence->periodEnd)->toDateString());
    }

    foreach ($calculator->upcoming($accelFirstHalf, deadlineDay('2026-01-01'), 12) as $occurrence) {
        expect($occurrence->dueDate->toDateString())
            ->toBe($resolver->dueDate(RemittanceFrequency::Accelerated1, $occurrence->periodStart->setDay(15))->toDateString());
    }

    foreach ($calculator->upcoming($accelSecondHalf, deadlineDay('2026-01-01'), 12) as $occurrence) {
        expect($occurrence->dueDate->toDateString())
            ->toBe($resolver->dueDate(RemittanceFrequency::Accelerated1, $occurrence->periodEnd)->toDateString());
    }
});
