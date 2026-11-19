<?php

namespace Database\Seeders\Concerns;

use App\Actions\Deadlines\CompleteDeadlineOccurrence;
use App\Actions\Deadlines\SaveDeadline;
use App\Enums\DeadlineStatus;
use App\Models\Company;
use App\Models\DeadlineOccurrence;
use App\Models\User;
use App\Services\Deadlines\Suggestions\DeadlineSuggester;
use App\Services\Deadlines\Suggestions\SuggestionChoices;
use App\Support\Reporting\ReportDatePresets;

/**
 * Demo deadlines for the docs screenshots and QA: suggested ones tracked from
 * the start of the fiscal year, with every past due date marked done except
 * the most recent of one series (so something shows as overdue).
 */
trait SeedsDemoDeadlines
{
    /**
     * @param  array<string, User|null>  $assignees  template key => assignee
     * @param  array<string, string>  $amounts  template key => usual amount
     * @param  list<array<string, mixed>>  $custom  extra SaveDeadline payloads
     */
    protected function seedDemoDeadlines(
        Company $company,
        User $owner,
        array $assignees,
        SuggestionChoices $choices,
        array $amounts = [],
        array $custom = [],
        ?string $leaveOverdue = null,
    ): void {
        $today = $company->currentDateTime();
        $trackFrom = ReportDatePresets::fiscalYearStart($today, (int) ($company->fiscal_year_start_month ?: 1))->toDateString();
        $suggestions = collect(DeadlineSuggester::for($company, $choices))->keyBy('key');
        $save = app(SaveDeadline::class);

        foreach ($assignees as $key => $assignee) {
            $suggestion = $suggestions->get($key);

            if ($suggestion === null) {
                continue;
            }

            $save->handle($company, [
                ...$suggestion->payload(),
                'assigned_user_id' => $assignee?->id,
                'track_from' => $trackFrom,
                'expected_amount' => $amounts[$key] ?? null,
            ]);
        }

        foreach ($custom as $payload) {
            $save->handle($company, ['track_from' => $trackFrom, ...$payload]);
        }

        $past = DeadlineOccurrence::query()
            ->with('deadline')
            ->where('status', DeadlineStatus::Open->value)
            ->where('due_date', '<', $today->toDateString())
            ->orderBy('due_date')
            ->get();

        $overdue = $leaveOverdue === null ? null : $past
            ->filter(fn (DeadlineOccurrence $o) => $o->deadline->template_key === $leaveOverdue)
            ->last();

        $complete = app(CompleteDeadlineOccurrence::class);

        foreach ($past->values() as $i => $occurrence) {
            if ($overdue !== null && $occurrence->is($overdue)) {
                continue;
            }

            $amount = $occurrence->deadline->kind->involvesPayment()
                ? number_format((($occurrence->deadline->expected_amount_cents ?? 184250) + ($i % 4) * 3175) / 100, 2, '.', '')
                : null;

            $complete->handle($company, $occurrence, [
                'completed_on' => $occurrence->due_date->subDays(2 + ($i % 6))->toDateString(),
                'completed_by_user_id' => $occurrence->assigned_user_id ?? $owner->id,
                'amount' => $amount,
                'confirmation_number' => 'CONF-'.str_pad((string) (40120 + $i * 37), 6, '0', STR_PAD_LEFT),
            ]);
        }
    }
}
