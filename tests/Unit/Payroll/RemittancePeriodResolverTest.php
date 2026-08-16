<?php

use App\Enums\RemittanceFrequency;
use App\Services\Payroll\RemittancePeriodResolver;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->resolver = new RemittancePeriodResolver;
});

it('computes monthly + quarterly due dates as the 15th of the following month', function () {
    expect($this->resolver->dueDate(RemittanceFrequency::Monthly, CarbonImmutable::parse('2025-06-30'))->toDateString())->toBe('2025-07-15')
        ->and($this->resolver->dueDate(RemittanceFrequency::Quarterly, CarbonImmutable::parse('2025-12-31'))->toDateString())->toBe('2026-01-15');
});

it('computes accelerated-1 due dates (25th same month / 10th next month)', function () {
    expect($this->resolver->dueDate(RemittanceFrequency::Accelerated1, CarbonImmutable::parse('2025-06-15'))->toDateString())->toBe('2025-06-25')
        ->and($this->resolver->dueDate(RemittanceFrequency::Accelerated1, CarbonImmutable::parse('2025-06-30'))->toDateString())->toBe('2025-07-10');
});

it('computes accelerated-2 due date as the 3rd working day, skipping weekends', function () {
    // June 30 2025 is a Monday → Tue Jul 1, Wed Jul 2, Thu Jul 3.
    expect($this->resolver->dueDate(RemittanceFrequency::Accelerated2, CarbonImmutable::parse('2025-06-30'))->toDateString())->toBe('2025-07-03');

    // Period ending Sat Jun 7 2025 → skip Sun, then Mon/Tue/Wed → Wed Jun 11.
    expect($this->resolver->dueDate(RemittanceFrequency::Accelerated2, CarbonImmutable::parse('2025-06-07'))->toDateString())->toBe('2025-06-11');
});

it('lists recent monthly periods newest-first with correct bounds', function () {
    $periods = $this->resolver->periods(RemittanceFrequency::Monthly, CarbonImmutable::parse('2025-06-20'), 3);

    expect($periods)->toHaveCount(3)
        ->and($periods[0]['start']->toDateString())->toBe('2025-06-01')
        ->and($periods[0]['end']->toDateString())->toBe('2025-06-30')
        ->and($periods[0]['due']->toDateString())->toBe('2025-07-15')
        ->and($periods[0]['label'])->toBe('June 2025')
        ->and($periods[1]['start']->toDateString())->toBe('2025-05-01')
        ->and($periods[2]['start']->toDateString())->toBe('2025-04-01');
});

it('lists accelerated-1 periods as two halves per month, newest-first', function () {
    $periods = $this->resolver->periods(RemittanceFrequency::Accelerated1, CarbonImmutable::parse('2025-06-20'), 2);

    expect($periods[0]['start']->toDateString())->toBe('2025-06-16')
        ->and($periods[0]['end']->toDateString())->toBe('2025-06-30')
        ->and($periods[1]['start']->toDateString())->toBe('2025-06-01')
        ->and($periods[1]['end']->toDateString())->toBe('2025-06-15');
});

it('lists accelerated-2 periods as four sub-month ranges, newest-first', function () {
    $periods = $this->resolver->periods(RemittanceFrequency::Accelerated2, CarbonImmutable::parse('2025-06-20'), 4);

    expect(array_map(fn ($p) => $p['start']->toDateString().'..'.$p['end']->toDateString(), $periods))->toBe([
        '2025-06-22..2025-06-30',
        '2025-06-15..2025-06-21',
        '2025-06-08..2025-06-14',
        '2025-06-01..2025-06-07',
    ]);
});
