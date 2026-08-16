<?php

namespace App\Actions\Payroll;

use App\Enums\PayBasis;
use App\Enums\PayRunStatus;
use App\Enums\TimeEntryStatus;
use App\Enums\TimeOffRequestStatus;
use App\Models\PayRun;
use App\Models\PayRunLine;
use App\Models\PayRunLineManualEarning;
use App\Models\TimeEntry;
use App\Services\Posting\PayRunPoster;
use App\Support\Payroll\BankedOvertimeRules;
use App\Support\Payroll\EarningTypeCatalogue;
use App\Support\Payroll\TimeEntryPayCodeCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pulls each employee's Approved, not-yet-paid time entries within the run's
 * period into the draft pay run, routed by the entry's pay code:
 *
 * - 'regular' fills an HOURLY employee's hours_worked, with hours past the
 *   company's weekly overtime threshold split into a 1.5× overtime earning each
 *   ISO week. Regular hours never drive salaried/commission pay, so for those
 *   bases they are deliberately left unpulled.
 * - Every other code (explicit overtime, stat holiday, sick, vacation, …)
 *   becomes an hours-based manual earning for ANY pay basis — a salaried
 *   employee's sick day must still draw the time-off balance at post time.
 *   Explicitly coded hours never participate in the weekly auto-split.
 *
 * Generated earnings are stamped source='time_entries' so a re-pull replaces
 * only its own rows and operator-entered earnings survive. Consumed entries are
 * stamped with the run so they're never double-counted; {@see PayRunPoster::void}
 * releases the stamps when a run is voided. Idempotent: a re-pull first releases
 * this run's prior stamps and recomputes cleanly.
 */
final class PullTimeEntriesIntoPayRun
{
    /** Marks manual earnings this pull owns (vs operator-entered rows). */
    public const SOURCE = PayRunLineManualEarning::SOURCE_TIME_ENTRIES;

    /**
     * The summary distinguishes the "nothing pulled" causes so the UI can say
     * why: regular hours only fill hourly employees' pay by design, approved
     * entries dated outside the period are deliberately left alone, and
     * by_code lets the UI break down what was pulled.
     *
     * @return array{employees: int, entries: int, hours: float, hourly_employees: int, outside_period: int, salaried_regular: int, by_code: array<string, float>}
     */
    public function handle(PayRun $run): array
    {
        if (! $run->status->isEditable()) {
            throw new RuntimeException('Only a draft pay run can pull time entries.');
        }

        return DB::transaction(function () use ($run): array {
            $threshold = $run->company->payroll_overtime_weekly_threshold_hours !== null
                ? (float) $run->company->payroll_overtime_weekly_threshold_hours
                : null;

            // Date columns compare as plain Y-m-d strings (SQLite compares the
            // literal text; a datetime-formatted binding would exclude the
            // period's first day there — the dual-DB rule).
            $start = CarbonImmutable::parse($run->period_start_date)->toDateString();
            $end = CarbonImmutable::parse($run->period_end_date)->toDateString();

            // Release entries previously pulled into THIS run so a re-pull is
            // clean — remembering whose hours the prior pull produced, so a
            // line whose entries no longer qualify (period changed, entries
            // rejected) doesn't keep paying hours its entries no longer back.
            $previouslyPulled = TimeEntry::query()
                ->where('pay_run_id', $run->id)
                ->pluck('contact_id')
                ->unique()
                ->all();

            TimeEntry::query()->where('pay_run_id', $run->id)->update(['pay_run_id' => null]);

            $run->loadMissing('lines.profile');

            $employees = 0;
            $consumed = 0;
            $hoursPulled = 0.0;
            $hourlyCount = 0;
            $salariedRegular = 0;
            $byCode = [];
            $contactIds = [];

            foreach ($run->lines as $line) {
                // pay_basis is an enum at runtime; compare the raw stored value so this
                // doesn't depend on model-cast type inference (a shared-model concern).
                $isHourly = $line->profile?->getRawOriginal('pay_basis') === PayBasis::Hourly->value;
                $hourlyCount += $isHourly ? 1 : 0;
                $contactIds[] = $line->contact_id;

                // Entries generated from a since-cancelled/denied request must
                // never resurrect into a run (a void releases their stamps, so
                // whereNull alone would happily re-consume them).
                $entries = TimeEntry::query()
                    ->where('contact_id', $line->contact_id)
                    ->where('status', TimeEntryStatus::Approved->value)
                    ->whereNull('pay_run_id')
                    ->whereBetween('date_worked', [$start, $end])
                    ->where(fn ($q) => $q->whereNull('time_off_request_id')
                        ->orWhereHas('timeOffRequest', fn ($r) => $r->whereNotIn('status', [TimeOffRequestStatus::Denied->value, TimeOffRequestStatus::Cancelled->value])))
                    ->get();

                // Regular hours pay nothing for salaried/commission employees:
                // leave them unstamped (and surface the count) rather than
                // silently consuming entries that didn't affect pay.
                if (! $isHourly) {
                    $regularEntries = $entries->where('pay_code', TimeEntryPayCodeCatalogue::REGULAR);
                    $salariedRegular += $regularEntries->count();
                    $entries = $entries->reject(fn (TimeEntry $e): bool => $e->pay_code === TimeEntryPayCodeCatalogue::REGULAR)->values();
                }

                // The pull owns its previously generated earnings — replace, never
                // touch operator-entered rows. (The code='overtime' clause clears
                // rows generated before the source column existed; operator OT rows
                // alongside a pull were already replaced by the pre-source pull.)
                $line->manualEarnings()
                    ->where(fn ($q) => $q->where('source', self::SOURCE)
                        ->orWhere(fn ($q2) => $q2->where('code', 'overtime')->whereNull('source')))
                    ->delete();

                // No in-period entries: generated earnings were cleared above (a
                // re-pull recomputes from current truth). Typed hours_worked is
                // operator input the pull doesn't own — UNLESS a prior pull of
                // this run produced it, in which case the entries that backed it
                // were just released (period changed / entries since rejected)
                // and leaving the hours would pay them here AND wherever the
                // released entries get pulled next.
                if ($entries->isEmpty()) {
                    if ($isHourly && in_array($line->contact_id, $previouslyPulled, true)) {
                        $line->update(['hours_worked' => 0]);
                    }

                    continue;
                }

                $pools = $entries->groupBy(fn (TimeEntry $entry): string => $entry->pay_code);

                $overtimeHours = 0.0;

                if ($isHourly) {
                    [$regular, $overtimeHours] = $this->split($pools->get(TimeEntryPayCodeCatalogue::REGULAR, new Collection), $threshold);
                    $line->update(['hours_worked' => $regular]);
                    $hoursPulled += $regular;
                    $byCode[TimeEntryPayCodeCatalogue::REGULAR] = ($byCode[TimeEntryPayCodeCatalogue::REGULAR] ?? 0.0) + $regular;
                }

                foreach ($pools as $code => $pool) {
                    if ($code === TimeEntryPayCodeCatalogue::REGULAR) {
                        continue;
                    }

                    $hours = (float) $pool->sum(fn (TimeEntry $entry) => (float) $entry->hours);

                    // Explicit overtime merges with the auto-split overtime hours.
                    if ($code === 'overtime') {
                        $hours += $overtimeHours;
                        $overtimeHours = 0.0;
                    }

                    if ($hours > 0.0) {
                        $this->createEarning($line, $code, $hours);
                        $hoursPulled += $hours;
                        $byCode[$code] = ($byCode[$code] ?? 0.0) + $hours;
                    }
                }

                if ($overtimeHours > 0.0) {
                    $this->createEarning($line, 'overtime', $overtimeHours);
                    $hoursPulled += $overtimeHours;
                    $byCode['overtime'] = ($byCode['overtime'] ?? 0.0) + $overtimeHours;
                }

                // Conditional claim: only stamp entries still unstamped, so two
                // operators pulling overlapping runs concurrently can't both pay
                // the same hours (the loser aborts cleanly and re-pulls).
                $claimed = TimeEntry::query()
                    ->whereIn('id', $entries->pluck('id'))
                    ->whereNull('pay_run_id')
                    ->update(['pay_run_id' => $run->id]);

                if ($claimed !== $entries->count()) {
                    throw new RuntimeException('Another pay run claimed some of these time entries while pulling — pull again.');
                }

                $employees++;
                $consumed += $entries->count();
            }

            // The pull changed the run's inputs — any prior calculation is
            // stale, so the run must be recalculated before it can post.
            if ($run->status !== PayRunStatus::Draft) {
                $run->forceFill(['status' => PayRunStatus::Draft])->save();
            }

            $outsidePeriod = $contactIds === [] ? 0 : TimeEntry::query()
                ->whereIn('contact_id', $contactIds)
                ->where('status', TimeEntryStatus::Approved->value)
                ->whereNull('pay_run_id')
                ->where(fn ($q) => $q->where('date_worked', '<', $start)->orWhere('date_worked', '>', $end))
                ->count();

            return [
                'employees' => $employees,
                'entries' => $consumed,
                'hours' => $hoursPulled,
                'hourly_employees' => $hourlyCount,
                'outside_period' => $outsidePeriod,
                'salaried_regular' => $salariedRegular,
                'by_code' => $byCode,
            ];
        });
    }

    /**
     * Generate one hours-based manual earning for a pulled pay code. Time-off
     * policy codes carry multiplier 1.0× and let CalculatePayRun's policy branch
     * decide pay + drawdown; wage codes (overtime, stat holiday) take their
     * multiplier from the catalogue.
     */
    private function createEarning(PayRunLine $line, string $code, float $hours): void
    {
        $flags = EarningTypeCatalogue::flags($code);

        // Banked overtime stores the employee's bank multiplier for display;
        // the engine re-derives it from the profile at calculation time.
        $multiplierBp = $code === TimeEntryPayCodeCatalogue::OVERTIME_BANKED && $line->profile
            ? BankedOvertimeRules::multiplierBpFor($line->profile)
            : $flags['multiplier_bp'];

        $line->manualEarnings()->create([
            'code' => $code,
            'name' => TimeEntryPayCodeCatalogue::label($code),
            'calc_kind' => 'hours',
            'hours' => $hours,
            'multiplier_bp' => $multiplierBp,
            't4_box' => $flags['t4_box'] === '14' ? null : $flags['t4_box'],
            'line_order' => 0,
            'source' => self::SOURCE,
        ]);
    }

    /**
     * Split regular entries into [regular, overtime] hours. With a weekly
     * threshold, hours past it each ISO week are overtime; without one, every
     * hour is regular. Only 'regular'-coded hours participate — explicitly
     * coded hours are never re-split.
     *
     * @param  Collection<int, TimeEntry>  $entries
     * @return array{0: float, 1: float}
     */
    private function split(Collection $entries, ?float $threshold): array
    {
        $total = (float) $entries->sum(fn (TimeEntry $entry) => (float) $entry->hours);

        if ($threshold === null || $threshold <= 0) {
            return [$total, 0.0];
        }

        $regular = 0.0;
        $overtime = 0.0;

        foreach ($entries->groupBy(fn (TimeEntry $entry) => $entry->date_worked->format('o-W')) as $week) {
            $weekHours = (float) $week->sum(fn (TimeEntry $entry) => (float) $entry->hours);
            $regular += min($weekHours, $threshold);
            $overtime += max(0.0, $weekHours - $threshold);
        }

        return [$regular, $overtime];
    }
}
