<?php

namespace App\Services\Recurring;

use App\Enums\RecurrenceDayAnchor;
use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;

/**
 * The minimal schedule shape {@see NextRunDateCalculator} needs to compute run
 * dates. Implemented by every recurring template (documents, journal entries,
 * report emails) so the date arithmetic lives in one place regardless of what
 * is generated.
 */
interface RecurringSchedule
{
    public function scheduleFrequency(): RecurrenceFrequency;

    public function scheduleDayOfMonth(): ?int;

    /**
     * How the run day is chosen within a month-based period. Implementations
     * must fall back to {@see RecurrenceDayAnchor::DayOfMonth} when unset.
     */
    public function scheduleDayAnchor(): RecurrenceDayAnchor;

    public function scheduleStartDate(): CarbonImmutable;
}
