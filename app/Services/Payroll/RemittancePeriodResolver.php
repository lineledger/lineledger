<?php

namespace App\Services\Payroll;

use App\Enums\RemittanceFrequency;
use Carbon\CarbonImmutable;

/**
 * Resolves source-deduction remittance periods + due dates from the employer's
 * CRA remitter frequency. Drives both the PD7A and the Revenu Québec schedules.
 *
 * Due dates (CRA):
 *   - Quarterly  → the 15th of the month after the quarter ends.
 *   - Monthly    → the 15th of the following month.
 *   - Accel-1    → the 1st–15th period is due the 25th of the same month; the
 *                  16th–end period is due the 10th of the next month.
 *   - Accel-2    → each of the four sub-month periods is due the 3rd working day
 *                  after it ends.
 *
 * v1 limitation: working days skip weekends only — there is no statutory-holiday
 * calendar. A holiday inside the window would push CRA's true due date later, so
 * this resolver's date is never late (the safe direction).
 */
class RemittancePeriodResolver
{
    /**
     * The most recent `$count` periods up to and including the one containing
     * `$asOf`, newest first — for the remittance period picker.
     *
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable, due: CarbonImmutable, label: string, key: string}>
     */
    public function periods(RemittanceFrequency $frequency, CarbonImmutable $asOf, int $count = 12): array
    {
        $periods = [];

        foreach ($this->rawPeriods($frequency, $asOf, $count) as [$start, $end]) {
            $periods[] = [
                'start' => $start,
                'end' => $end,
                'due' => $this->dueDate($frequency, $end),
                'label' => $this->label($frequency, $start, $end),
                'key' => $start->toDateString(),
            ];
        }

        return $periods;
    }

    /**
     * The CRA remittance due date for a period ending on `$periodEnd`.
     */
    public function dueDate(RemittanceFrequency $frequency, CarbonImmutable $periodEnd): CarbonImmutable
    {
        return match ($frequency) {
            // 15th of the month after the period ends.
            RemittanceFrequency::Quarterly, RemittanceFrequency::Monthly => $periodEnd->addDay()->setDay(15),
            // First half (ends the 15th) → 25th same month; second half → 10th next month.
            RemittanceFrequency::Accelerated1 => $periodEnd->day === 15
                ? $periodEnd->setDay(25)
                : $periodEnd->addDay()->setDay(10),
            // 3rd working day after the sub-period ends.
            RemittanceFrequency::Accelerated2 => $this->addWorkingDays($periodEnd, 3),
        };
    }

    /**
     * The (start, end) bounds of the `$count` most recent periods, newest first.
     *
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function rawPeriods(RemittanceFrequency $frequency, CarbonImmutable $asOf, int $count): array
    {
        $out = [];

        if ($frequency === RemittanceFrequency::Monthly) {
            for ($i = 0; $i < $count; $i++) {
                $monthStart = $asOf->startOfMonth()->subMonthsNoOverflow($i);
                $out[] = [$monthStart, $monthStart->endOfMonth()];
            }

            return $out;
        }

        if ($frequency === RemittanceFrequency::Quarterly) {
            for ($i = 0; $i < $count; $i++) {
                $qStart = $asOf->startOfQuarter()->subQuartersNoOverflow($i);
                $out[] = [$qStart, $qStart->endOfQuarter()];
            }

            return $out;
        }

        // Accelerated: sub-month periods, newest first within each month.
        $subRanges = $frequency === RemittanceFrequency::Accelerated1
            ? [[16, null], [1, 15]]                 // second half first (newer)
            : [[22, null], [15, 21], [8, 14], [1, 7]];

        for ($m = 0; count($out) < $count; $m++) {
            $monthStart = $asOf->startOfMonth()->subMonthsNoOverflow($m);

            foreach ($subRanges as [$from, $to]) {
                $start = $monthStart->setDay($from);
                $end = $to === null ? $monthStart->endOfMonth() : $monthStart->setDay($to);
                $out[] = [$start, $end];

                if (count($out) >= $count) {
                    break;
                }
            }
        }

        return $out;
    }

    private function label(RemittanceFrequency $frequency, CarbonImmutable $start, CarbonImmutable $end): string
    {
        return match ($frequency) {
            RemittanceFrequency::Monthly => $start->format('F Y'),
            RemittanceFrequency::Quarterly => 'Q'.$start->quarter.' '.$start->year,
            default => $start->format('M j').'–'.$end->format('j, Y'),
        };
    }

    /**
     * Add working days (skipping Saturdays + Sundays) to a date.
     */
    private function addWorkingDays(CarbonImmutable $date, int $days): CarbonImmutable
    {
        $result = $date;
        $added = 0;

        while ($added < $days) {
            $result = $result->addDay();

            if (! $result->isWeekend()) {
                $added++;
            }
        }

        return $result;
    }
}
