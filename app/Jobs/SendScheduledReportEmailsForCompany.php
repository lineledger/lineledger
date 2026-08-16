<?php

namespace App\Jobs;

use App\Enums\RecurrenceEndType;
use App\Models\Company;
use App\Models\MemorizedReport;
use App\Models\ReportEmailSchedule;
use App\Notifications\Reports\ReportEmailNotification;
use App\Services\Recurring\NextRunDateCalculator;
use App\Support\Reporting\RenderableReports;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Emails every due report schedule for one company. Isolated per company so a
 * slow or erroring tenant cannot block the others. "Today" is evaluated in the
 * company's own timezone so a schedule anchored to a calendar day fires on that
 * company's day, not UTC's.
 *
 * Missed runs are never caught up: a schedule whose next_run_date is far in the
 * past sends exactly once, then next_run_date is fast-forwarded past today —
 * resending stale copies of the same report is noise, unlike draft generation.
 */
class SendScheduledReportEmailsForCompany implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $companyId) {}

    public function handle(NextRunDateCalculator $calculator): void
    {
        $company = Company::query()->findOrFail($this->companyId);

        $this->sendDue($company, $calculator);
    }

    /**
     * Send every due schedule once and advance it. Returns the number of
     * notifications dispatched (one per renderable target report).
     */
    public function sendDue(Company $company, NextRunDateCalculator $calculator): int
    {
        $today = $company->currentDateTime()->startOfDay();
        $sent = 0;

        ReportEmailSchedule::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->due($today->toDateString())
            ->orderBy('id')
            ->each(function (ReportEmailSchedule $schedule) use ($company, $calculator, $today, &$sent): void {
                $sent += $this->process($schedule, $company, $calculator, $today);
            });

        return $sent;
    }

    protected function process(
        ReportEmailSchedule $schedule,
        Company $company,
        NextRunDateCalculator $calculator,
        CarbonImmutable $today,
    ): int {
        $reports = $this->renderableTargets($schedule);

        if ($reports === []) {
            $schedule->is_active = false;
            $schedule->paused_reason = $schedule->memorized_report_id !== null
                ? __('The memorized report no longer exists or cannot be emailed.')
                : __('The group has no reports that can be emailed.');
            $schedule->save();

            return 0;
        }

        $user = $schedule->user;

        foreach ($reports as $report) {
            $entry = RenderableReports::get($report->report_key);

            Notification::route('mail', $schedule->recipients)->notify(new ReportEmailNotification(
                company: $company,
                reportKey: $report->report_key,
                reportLabel: $entry['label'],
                settings: $report->settings ?? [],
                subjectLine: $schedule->subject,
                body: $schedule->body,
                attachXlsx: $schedule->attach_xlsx && RenderableReports::supports($report->report_key, 'xlsx'),
                resolvePresets: true,
                replyToAddress: $user?->email,
                senderName: $user?->name,
            ));
        }

        $this->advance($schedule, $calculator, $today);

        return count($reports);
    }

    /**
     * The memorized reports this schedule should email, filtered to ones that
     * can actually render a PDF. Empty when the target was deleted or nothing
     * in it is renderable — the caller pauses the schedule instead of crashing.
     *
     * @return list<MemorizedReport>
     */
    protected function renderableTargets(ReportEmailSchedule $schedule): array
    {
        if ($schedule->memorized_report_id !== null) {
            $candidates = collect([$schedule->memorizedReport()->withoutGlobalScopes()->first()]);
        } elseif ($schedule->memorized_report_group_id !== null) {
            $group = $schedule->memorizedReportGroup()->withoutGlobalScopes()->first();
            $candidates = $group?->memorizedReports()->withoutGlobalScopes()->get() ?? collect();
        } else {
            $candidates = collect();
        }

        return $candidates
            ->filter(fn (?MemorizedReport $report): bool => $report !== null
                && RenderableReports::supports($report->report_key, 'pdf'))
            ->values()
            ->all();
    }

    /**
     * One send per run regardless of how many runs were missed: advance
     * next_run_date hop by hop until it lands after today, then apply the
     * schedule's end rule.
     */
    protected function advance(ReportEmailSchedule $schedule, NextRunDateCalculator $calculator, CarbonImmutable $today): void
    {
        $schedule->occurrences_generated = (int) $schedule->occurrences_generated + 1;
        $schedule->last_sent_at = now();

        $current = $schedule->next_run_date !== null
            ? CarbonImmutable::parse($schedule->next_run_date->toDateString())
            : $today;

        $next = $calculator->next($schedule, $current);
        while ($next->lessThanOrEqualTo($today)) {
            $next = $calculator->next($schedule, $next);
        }
        $schedule->next_run_date = $next;

        $this->applyEndRule($schedule);
        $schedule->save();
    }

    protected function applyEndRule(ReportEmailSchedule $schedule): void
    {
        switch ($schedule->end_type) {
            case RecurrenceEndType::OnDate:
                if ($schedule->end_date !== null
                    && $schedule->next_run_date !== null
                    && $schedule->next_run_date->greaterThan($schedule->end_date)) {
                    $schedule->is_active = false;
                    $schedule->next_run_date = null;
                }
                break;

            case RecurrenceEndType::AfterOccurrences:
                if ($schedule->max_occurrences !== null
                    && (int) $schedule->occurrences_generated >= (int) $schedule->max_occurrences) {
                    $schedule->is_active = false;
                    $schedule->next_run_date = null;
                }
                break;

            case RecurrenceEndType::Never:
            default:
                break;
        }
    }
}
