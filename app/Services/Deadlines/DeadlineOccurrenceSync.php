<?php

namespace App\Services\Deadlines;

use App\Enums\DeadlineStatus;
use App\Models\Company;
use App\Models\Deadline;
use App\Models\DeadlineOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Keeps a deadline's stored occurrences in step with its rule.
 *
 * Occurrences are materialized a rolling {@see self::HORIZON_MONTHS} ahead so
 * each one can be assigned, completed or skipped on its own. An occurrence
 * nobody has touched (still Open, never edited) follows the rule: its dates
 * and assignee are rewritten, and it is removed when the rule no longer
 * produces it. Anything completed, skipped or edited is left exactly as it is.
 */
final class DeadlineOccurrenceSync
{
    /** How far ahead occurrences are stored. */
    public const int HORIZON_MONTHS = 15;

    public function __construct(private readonly DeadlineScheduleCalculator $calculator) {}

    /**
     * Brings one deadline's occurrences in line with its rule, as of the
     * organization's `$today`.
     */
    public function sync(Deadline $deadline, ?CarbonImmutable $today = null): void
    {
        $today = DeadlineScheduleCalculator::dateOnly($today ?? $this->todayFor($deadline));

        /** @var Collection<string, DeadlineOccurrence> $existing */
        $existing = DeadlineOccurrence::query()
            ->where('deadline_id', $deadline->id)
            ->get()
            ->keyBy('occurrence_key');

        if (! $deadline->isLive()) {
            // Archived: nothing untouched is owed any more. Paused: only the
            // future stops; what was already due stays on the list.
            $existing
                ->filter(fn (DeadlineOccurrence $o): bool => $this->isUntouched($o)
                    && ($deadline->isArchived() || $o->due_date->toDateString() >= $today->toDateString()))
                ->each(fn (DeadlineOccurrence $o) => $o->delete());

            return;
        }

        $from = $deadline->track_from ?? $today;
        $generated = $this->calculator->between(
            $deadline->rule(),
            $from,
            $today->addMonthsNoOverflow(self::HORIZON_MONTHS),
        );

        $keep = [];

        foreach ($generated as $scheduled) {
            $keep[$scheduled->key] = true;
            $occurrence = $existing->get($scheduled->key);

            $attributes = [
                'period_start' => $scheduled->periodStart?->toDateString(),
                'period_end' => $scheduled->periodEnd?->toDateString(),
                'unadjusted_due_date' => $scheduled->unadjustedDueDate->toDateString(),
                'due_date' => $scheduled->dueDate->toDateString(),
                'assigned_user_id' => $deadline->assigned_user_id,
            ];

            if ($occurrence === null) {
                $occurrence = new DeadlineOccurrence([
                    'deadline_id' => $deadline->id,
                    'occurrence_key' => $scheduled->key,
                    'status' => DeadlineStatus::Open,
                    ...$attributes,
                ]);
                // The scheduler runs with no company bound; the creating guard
                // overrides this with the bound company inside a request.
                $occurrence->company_id = $deadline->company_id;
                $occurrence->save();

                continue;
            }

            if ($this->isUntouched($occurrence)) {
                $occurrence->fill($attributes);

                if ($occurrence->isDirty()) {
                    $occurrence->save();
                }
            }
        }

        $existing
            ->filter(fn (DeadlineOccurrence $o, string $key): bool => ! isset($keep[$key]) && $this->isUntouched($o))
            ->each(fn (DeadlineOccurrence $o) => $o->delete());
    }

    /**
     * Rolls every live deadline of the organization forward to the horizon.
     * Run daily; returns how many deadlines were synced.
     */
    public function extend(Company $company): int
    {
        $today = $company->currentDateTime();
        $count = 0;

        Deadline::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('archived_at')
            ->where('is_active', true)
            ->orderBy('id')
            ->each(function (Deadline $deadline) use ($today, &$count): void {
                $this->sync($deadline, $today);
                $count++;
            });

        return $count;
    }

    /**
     * Open and never edited: the rule still owns it.
     */
    public function isUntouched(DeadlineOccurrence $occurrence): bool
    {
        return $occurrence->status === DeadlineStatus::Open && $occurrence->customized_at === null;
    }

    private function todayFor(Deadline $deadline): CarbonImmutable
    {
        $company = $deadline->relationLoaded('company') ? $deadline->company : $deadline->company()->withoutGlobalScopes()->first();

        return $company instanceof Company ? $company->currentDateTime() : CarbonImmutable::now();
    }
}
