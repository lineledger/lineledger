<?php

namespace App\Services\Recurring;

use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;

/**
 * The minimal schedule shape {@see NextRunDateCalculator} needs to compute run
 * dates. Implemented by every recurring template (documents and journal entries)
 * so the date arithmetic lives in one place regardless of what is generated.
 */
interface RecurringSchedule
{
    public function scheduleFrequency(): RecurrenceFrequency;

    public function scheduleDayOfMonth(): ?int;

    public function scheduleStartDate(): CarbonImmutable;
}
